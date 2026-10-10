<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use App\Settings\ExpirationSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Une seule commande en cours a la fois, et elle perime.
 *
 * Le blocage existait deja — `valide()` refuse tant qu'une commande n'est pas
 * reglee — mais sans issue : des commandes de fevrier 2024 bloquaient encore
 * des comptes. Le proprietaire a tranche : les deux statuts bloquent, « En
 * attente » (1) comme « En attente paiement » (5), et quinze minutes plus
 * tard la commande s'eteint d'elle-meme.
 *
 * L'expiration est calculee a la lecture ET ecrite par une tache planifiee :
 * un planificateur arrete ne doit pas rendre la regle fausse.
 */
class CommandeUniqueEtExpirationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Town $town;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [Commande::STATUT_EN_ATTENTE, 'En attente', 'en-attente'],
            [Commande::STATUT_EN_COURS, 'En cours', 'en-cours'],
            [Commande::STATUT_LIVREE, 'Livrer', 'livrer'],
            [Commande::STATUT_ANNULEE, 'Annuler', 'annuler'],
            [Commande::STATUT_ATTENTE_PAIEMENT, 'En attente paiement', 'en-attente-paiement'],
        ] as [$id, $titre, $slug]) {
            Status::query()->firstOrCreate(['id' => $id], ['title' => $titre, 'slug' => $slug]);
        }

        $this->town = Town::factory()->create();
        $this->client = User::factory()->create();
        Sanctum::actingAs($this->client, ['*']);
    }

    private function commande(int $statut, ?User $client = null): Commande
    {
        return Commande::query()->create([
            'user_id' => ($client ?? $this->client)->id,
            'status_id' => $statut,
            'town_id' => $this->town->id,
            'refernce' => 'CMD-'.uniqid(),
            'global_price' => 10,
            'adresse_delivery' => 'Avenue du Commerce 12',
        ]);
    }

    /** Fait passer l'echeance sans toucher au reste, et sans tache planifiee. */
    private function perimer(Commande $commande): Commande
    {
        $commande->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();

        return $commande;
    }

    /**
     * Le corps vide suffit : le controle de commande en cours est le tout
     * premier geste de `valide()`. Un corps complet ferait appeler FlexPay.
     */
    private function tenterUneNouvelleCommande(): TestResponse
    {
        return $this->postJson('/api/user/commande/valide', []);
    }

    private function estBloquee(TestResponse $reponse): bool
    {
        return $reponse->json('title') === 'Commande en attente';
    }

    public function test_une_commande_en_attente_paiement_bloque_une_nouvelle_commande(): void
    {
        $bloquante = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);

        $reponse = $this->tenterUneNouvelleCommande()->assertStatus(400);

        $this->assertTrue($this->estBloquee($reponse));
        $this->assertStringContainsString($bloquante->refernce, $reponse->json('message'));
        $this->assertSame($bloquante->refernce, $reponse->json('error.reference'));
    }

    public function test_une_commande_en_attente_bloque_aussi(): void
    {
        // Arbitrage du proprietaire : les deux statuts bloquent. Un client
        // n'a qu'une commande en cours a la fois, payee ou non.
        $this->commande(Commande::STATUT_EN_ATTENTE);

        $this->assertTrue($this->estBloquee($this->tenterUneNouvelleCommande()));
    }

    public function test_une_commande_en_cours_ne_bloque_pas(): void
    {
        // Statut 2 : le restaurant prepare, le livreur roule. Elle est reglee,
        // elle n'a aucune raison d'empecher la suivante.
        $this->commande(Commande::STATUT_EN_COURS);

        $this->assertFalse($this->estBloquee($this->tenterUneNouvelleCommande()));
    }

    public function test_une_commande_perimee_ne_bloque_plus_et_passe_a_annuler(): void
    {
        $bloquante = $this->perimer($this->commande(Commande::STATUT_ATTENTE_PAIEMENT));

        $this->assertFalse($this->estBloquee($this->tenterUneNouvelleCommande()));

        // Eteinte en base, pas seulement ignoree a la lecture : sinon elle
        // resterait affichee « en attente » au client et au personnel.
        $bloquante->refresh();
        $this->assertSame(Commande::STATUT_ANNULEE, (int) $bloquante->status_id);
        $this->assertNotNull($bloquante->cancel_at);
    }

    public function test_apres_paiement_une_nouvelle_commande_passe(): void
    {
        $payee = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);
        $payee->status_id = Commande::STATUT_EN_COURS;
        $payee->save();

        $this->assertFalse($this->estBloquee($this->tenterUneNouvelleCommande()));
    }

    public function test_apres_annulation_une_nouvelle_commande_passe(): void
    {
        $annulee = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);

        $this->postJson('/api/user/commande/cancel', [
            'uid' => Cipher::Encrypt($annulee->id),
        ])->assertStatus(201);

        $this->assertSame(Commande::STATUT_ANNULEE, (int) $annulee->refresh()->status_id);
        $this->assertFalse($this->estBloquee($this->tenterUneNouvelleCommande()));
    }

    public function test_le_panier_d_un_autre_client_ne_bloque_personne(): void
    {
        $this->commande(Commande::STATUT_ATTENTE_PAIEMENT, User::factory()->create());

        $this->assertFalse($this->estBloquee($this->tenterUneNouvelleCommande()));
    }

    public function test_l_ajout_au_panier_refuse_de_reecrire_une_commande_envoyee_au_paiement(): void
    {
        $bloquante = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);

        $reponse = $this->postJson('/api/user/commande/add', [
            'adresse' => ['town' => ['uid' => Cipher::Encrypt($this->town->id)], 'adresse' => 'X', 'street' => 'Y', 'number_street' => '1'],
            'pricing' => ['frais_livraison' => 1, 'service_price' => 1, 'currency' => ['id' => 1]],
            'products' => [],
        ])->assertStatus(400);

        $this->assertSame('Commande en attente', $reponse->json('title'));
        $this->assertSame($bloquante->refernce, $reponse->json('error.reference'));
    }

    public function test_le_delai_vient_des_reglages(): void
    {
        ExpirationSettings::fake(['minutes' => 45], loadMissingValues: false);

        $commande = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);

        $this->assertEqualsWithDelta(45, now()->diffInMinutes($commande->expires_at), 1);
    }

    public function test_la_tache_planifiee_marque_les_bonnes_lignes_et_laisse_les_autres(): void
    {
        $perimee = $this->perimer($this->commande(Commande::STATUT_ATTENTE_PAIEMENT));
        $recente = $this->commande(Commande::STATUT_EN_ATTENTE, User::factory()->create());
        $enCours = $this->perimer($this->commande(Commande::STATUT_EN_COURS, User::factory()->create()));
        $livree = $this->perimer($this->commande(Commande::STATUT_LIVREE, User::factory()->create()));

        $this->artisan('expirations:traiter')->assertSuccessful();

        $this->assertSame(Commande::STATUT_ANNULEE, (int) $perimee->refresh()->status_id);
        $this->assertSame(Commande::STATUT_EN_ATTENTE, (int) $recente->refresh()->status_id);
        // Statut 2 et 3 : deja tranches, la tache n'a rien a y faire, meme
        // avec une echeance ancienne restee en base.
        $this->assertSame(Commande::STATUT_EN_COURS, (int) $enCours->refresh()->status_id);
        $this->assertSame(Commande::STATUT_LIVREE, (int) $livree->refresh()->status_id);
    }

    public function test_la_simulation_de_la_tache_n_ecrit_rien(): void
    {
        $perimee = $this->perimer($this->commande(Commande::STATUT_ATTENTE_PAIEMENT));

        $this->artisan('expirations:traiter', ['--simuler' => true])->assertSuccessful();

        $this->assertSame(Commande::STATUT_ATTENTE_PAIEMENT, (int) $perimee->refresh()->status_id);
    }

    public function test_une_commande_sans_echeance_ne_disparait_pas(): void
    {
        // Les lignes heritees n'ont pas d'`expires_at`. « Pas d'echeance » se
        // lit « ne jamais expirer » : les faire disparaitre d'un coup ferait
        // perdre des commandes vivantes. C'est la migration qui les traite.
        $ancienne = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);
        $ancienne->forceFill(['expires_at' => null])->saveQuietly();

        $this->artisan('expirations:traiter')->assertSuccessful();

        $this->assertSame(Commande::STATUT_ATTENTE_PAIEMENT, (int) $ancienne->refresh()->status_id);
        $this->assertTrue($this->estBloquee($this->tenterUneNouvelleCommande()));
    }
}
