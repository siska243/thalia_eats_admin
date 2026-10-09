<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use App\Settings\ExpirationSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * L'echeance de reglement d'une commande, pour le compte a rebours client.
 *
 * La colonne existait en base mais ne sortait pas de l'API : l'ecran mobile
 * etait ecrit pour un champ absent, et aucun minuteur ne tournait.
 *
 * Elle ne sort que tant qu'un reglement est reellement attendu. Une commande
 * livree qui porterait encore une date ferait decompter l'application sur
 * une commande finie ; une commande deja perimee la ferait decompter a
 * l'envers.
 */
class EcheanceCommandeTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Town $town;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create();
        $this->town = Town::factory()->create();

        foreach ([1, 2, 3, 4, 5] as $id) {
            Status::query()->firstOrCreate(['id' => $id], ['title' => "Statut {$id}", 'slug' => "statut-{$id}"]);
        }

        Sanctum::actingAs($this->client, ['*']);
    }

    private function commande(int $statut): Commande
    {
        return Commande::query()->create([
            'user_id' => $this->client->id,
            'status_id' => $statut,
            'town_id' => $this->town->id,
            'refernce' => 'CMD-'.uniqid(),
            'global_price' => 10,
        ]);
    }

    private function echeanceRendue(Commande $commande): mixed
    {
        return $this->getJson('/api/user/commande/show-order/'.Cipher::Encrypt($commande->id))
            ->assertSuccessful()
            ->json('expires_at');
    }

    public function test_l_echeance_sort_sur_une_commande_en_attente_de_paiement(): void
    {
        $commande = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);

        $echeance = $this->echeanceRendue($commande);

        $this->assertNotNull($echeance);
        $this->assertEqualsWithDelta(
            15,
            now()->diffInMinutes($echeance),
            1,
            "L'echeance doit suivre le reglage d'expiration.",
        );
    }

    public function test_l_echeance_est_nulle_sur_une_commande_en_cours(): void
    {
        $this->assertNull($this->echeanceRendue($this->commande(Commande::STATUT_EN_COURS)));
    }

    public function test_l_echeance_est_nulle_sur_une_commande_livree(): void
    {
        $this->assertNull($this->echeanceRendue($this->commande(Commande::STATUT_LIVREE)));
    }

    public function test_l_echeance_est_nulle_sur_une_commande_annulee(): void
    {
        $this->assertNull($this->echeanceRendue($this->commande(Commande::STATUT_ANNULEE)));
    }

    /** Pas de decompte negatif a cote d'un statut qui dit le contraire. */
    public function test_l_echeance_est_nulle_sur_une_commande_deja_perimee(): void
    {
        $commande = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);

        // `forceFill` + `saveQuietly` : le crochet `saving` repousserait
        // l'echeance a chaque enregistrement.
        $commande->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();

        $this->assertNull($this->echeanceRendue($commande));
    }

    /**
     * Le delai vient des reglages, pas du fichier de configuration.
     *
     * Sans ce test, la valeur pourrait continuer d'etre lue dans
     * `config/expiration.php` sans que rien ne le signale.
     */
    public function test_changer_le_reglage_change_l_echeance(): void
    {
        ExpirationSettings::fake(['minutes' => 45], loadMissingValues: false);
        config()->set('expiration.minutes', 15);

        $commande = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);

        $this->assertEqualsWithDelta(45, now()->diffInMinutes($commande->expires_at), 1);
        $this->assertEqualsWithDelta(45, now()->diffInMinutes($this->echeanceRendue($commande)), 1);
    }
}
