<?php

namespace Tests\Feature\Api;

use App\Models\Precommande;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LienPaiementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([
            'code' => 0, 'orderNumber' => 'TEST-ORDER-1', 'message' => 'Transaction initiee',
        ], 200)]);
    }

    private function lien(Precommande $p, ?\DateTimeInterface $expiration = null): string
    {
        return URL::temporarySignedRoute(
            'precommande.paiement',
            $expiration ?: $p->expires_at,
            ['uid' => Cipher::Encrypt($p->id)],
        );
    }

    private function lienInitiation(Precommande $p, ?\DateTimeInterface $expiration = null): string
    {
        return URL::temporarySignedRoute(
            'precommande.paiement.initier',
            $expiration ?: $p->expires_at,
            ['uid' => Cipher::Encrypt($p->id)],
        );
    }

    public function test_un_lien_valide_affiche_le_recapitulatif(): void
    {
        $p = Precommande::factory()->create();

        $this->get($this->lien($p))->assertStatus(200)->assertSee($p->refernce);
    }

    public function test_le_recapitulatif_masque_l_adresse_et_le_destinataire(): void
    {
        // Le lien signe peut avoir ete transfere ou journalise : le porteur
        // ne doit pas y apprendre ou et chez qui livrer.
        $p = Precommande::factory()->create([
            'adresse_delivery' => 'Avenue Kasa-Vubu 45, Ibanda',
            'recipient_name' => 'Josephine Mukendi',
        ]);

        $reponse = $this->get($this->lien($p))->assertStatus(200);

        $reponse->assertDontSee('Avenue Kasa-Vubu 45, Ibanda', false);
        $reponse->assertDontSee('Josephine Mukendi', false);

        $reponse->assertSee('Ave******', false);
        $reponse->assertSee('Jos******', false);
    }

    public function test_le_masque_ne_trahit_pas_la_longueur_de_la_valeur(): void
    {
        // Str::mask() remplacait caractere par caractere : le nombre
        // d'asterisques disait la longueur exacte de l'adresse. La marque est
        // desormais de largeur fixe.
        $court = Precommande::factory()->create(['adresse_delivery' => 'Av. Lac']);
        $long = Precommande::factory()->create([
            'adresse_delivery' => 'Avenue de la Democratie 1428, quartier Nyalukemba',
        ]);

        $this->get($this->lien($court))->assertSee('Av.******', false);
        $this->get($this->lien($long))->assertSee('Ave******', false);
    }

    public function test_une_valeur_trop_courte_n_est_jamais_rendue_en_clair(): void
    {
        // Str::mask('Eve', '*', 3) rendait « Eve » : rien n'etait masque.
        $p = Precommande::factory()->create([
            'recipient_name' => 'Eve',
            'adresse_delivery' => 'Av',
        ]);

        $reponse = $this->get($this->lien($p))->assertStatus(200);

        $reponse->assertDontSee('Eve', false);
        $reponse->assertSee('pour ******.', false);
    }

    public function test_le_recapitulatif_montre_toujours_les_plats_et_le_total(): void
    {
        // Masquer ne doit pas empecher le payeur de savoir ce qu'il paie :
        // le plat commande doit rester nomme, et le total lisible.
        $p = Precommande::factory()->create(['total' => 5500]);
        $produit = \App\Models\Product::factory()->create(['title' => 'Poulet moambe']);

        $p->products()->create([
            'product_id' => $produit->id,
            'quantity' => 2,
            'price' => 1500,
        ]);

        $this->get($this->lien($p->fresh('products')))
            ->assertStatus(200)
            ->assertSee('Poulet moambe', false)
            ->assertSee('5500', false);
    }

    public function test_la_page_d_indisponibilite_ne_livre_pas_la_reference_entiere(): void
    {
        $p = Precommande::factory()->expiree()->create();

        $this->get($this->lien($p, now()->addHour()))
            ->assertStatus(410)
            ->assertDontSee($p->refernce, false);
    }

    public function test_un_lien_sans_signature_est_refuse(): void
    {
        $p = Precommande::factory()->create();

        $this->get('/paiement/precommande/'.Cipher::Encrypt($p->id))->assertStatus(403);
    }

    public function test_un_lien_dont_la_signature_est_alteree_est_refuse(): void
    {
        $p = Precommande::factory()->create();

        $this->get($this->lien($p).'X')->assertStatus(403);
    }

    public function test_un_lien_vers_une_precommande_expiree_ne_paie_rien(): void
    {
        $p = Precommande::factory()->expiree()->create();

        // Signature encore valable, mais la pré-commande ne l'est plus :
        // la signature seule ne suffit jamais.
        $this->get($this->lien($p, now()->addHour()))
            ->assertStatus(410)
            ->assertSee('expir', false);
    }

    public function test_un_lien_rejoue_apres_paiement_ne_paie_rien(): void
    {
        $p = Precommande::factory()->payee()->create();

        $this->get($this->lien($p, now()->addHour()))->assertStatus(410);
    }

    public function test_l_initiation_enregistre_la_reference_de_paiement(): void
    {
        $p = Precommande::factory()->create();

        $this->post($this->lienInitiation($p), [
            'phone' => '+243810000000',
        ])->assertRedirect();

        $this->assertSame('TEST-ORDER-1', $p->fresh()->reference_paiement);
    }

    public function test_l_initiation_envoie_le_total_fige_a_flexpay(): void
    {
        $p = Precommande::factory()->create(['total' => 5500]);

        $this->post($this->lienInitiation($p), [
            'phone' => '+243810000000',
        ]);

        Http::assertSent(fn ($request) => (float) $request['amount'] === 5500.0);
    }

    public function test_l_initiation_sur_une_precommande_expiree_est_refusee(): void
    {
        $p = Precommande::factory()->expiree()->create();

        $this->post($this->lienInitiation($p, now()->addHour()), [
            'phone' => '+243810000000',
        ])->assertStatus(410);

        Http::assertNothingSent();
    }

    public function test_un_post_sans_signature_est_refuse(): void
    {
        $p = Precommande::factory()->create();

        $this->post('/paiement/precommande/'.Cipher::Encrypt($p->id), [
            'phone' => '+243810000000',
        ])->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_un_post_avec_la_signature_d_une_autre_precommande_est_refuse(): void
    {
        $mienne = Precommande::factory()->create();
        $autre = Precommande::factory()->create();

        // Signature valide, mais emise pour une AUTRE pre-commande.
        $urlAutre = URL::temporarySignedRoute(
            'precommande.paiement.initier',
            $autre->expires_at,
            ['uid' => Cipher::Encrypt($autre->id)],
        );

        // On remplace l'uid dans le chemin en gardant la signature.
        $forgee = str_replace(
            Cipher::Encrypt($autre->id),
            Cipher::Encrypt($mienne->id),
            $urlAutre
        );

        $this->post($forgee, ['phone' => '+243810000000'])->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_un_post_signe_initie_bien_le_paiement(): void
    {
        $p = Precommande::factory()->create(['total' => 5500]);

        $url = URL::temporarySignedRoute(
            'precommande.paiement.initier',
            $p->expires_at,
            ['uid' => Cipher::Encrypt($p->id)],
        );

        $this->post($url, ['phone' => '+243810000000'])->assertRedirect();

        $this->assertSame('TEST-ORDER-1', $p->fresh()->reference_paiement);
    }

    public function test_l_application_peut_initier_le_paiement_sans_lien(): void
    {
        $moi = \App\Models\User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($moi, ['*']);

        $p = Precommande::factory()->create(['user_id' => $moi->id, 'total' => 5500]);

        $this->postJson('/api/precommandes/'.Cipher::Encrypt($p->id).'/paiement', [
            'phone' => '+243810000000',
        ])->assertStatus(200);

        $this->assertSame('TEST-ORDER-1', $p->fresh()->reference_paiement);
    }

    public function test_l_application_ne_paie_pas_la_precommande_d_un_autre(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\User::factory()->create(), ['*']);

        $p = Precommande::factory()->create();

        $this->postJson('/api/precommandes/'.Cipher::Encrypt($p->id).'/paiement', [
            'phone' => '+243810000000',
        ])->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_un_assistant_ne_peut_pas_declencher_un_paiement(): void
    {
        // L'ability n'existe pas : le lien de paiement EST la confirmation
        // humaine, un agent ne doit jamais engager d'argent seul.
        $moi = \App\Models\User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($moi, \App\Enums\TokenAbility::agent());

        $p = Precommande::factory()->create(['user_id' => $moi->id]);

        $this->postJson('/api/precommandes/'.Cipher::Encrypt($p->id).'/paiement', [
            'phone' => '+243810000000',
        ])->assertStatus(403);

        Http::assertNothingSent();
    }
}
