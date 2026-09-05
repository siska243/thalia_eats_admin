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

    /**
     * Http::fake() empile les stubs et le premier qui repond gagne : celui de
     * setUp() intercepterait tout. On repart donc d'une fabrique neuve quand un
     * test a besoin d'une autre reponse de la passerelle.
     */
    private function reponseFlexPay(array $reponse): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstances();
        Http::fake(['*' => Http::response($reponse, 200)]);
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

        // Le mobile money ne redirige plus vers l'accueil sans un mot : on rend
        // la page qui dit de valider sur le combine.
        $this->post($this->lienInitiation($p), [
            'phone' => '+243810000000',
        ])->assertStatus(200)->assertSee('Validez sur votre téléphone', false);

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

        $this->post($url, ['phone' => '+243810000000'])->assertStatus(200);

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

    public function test_le_formulaire_demande_les_coordonnees_quand_elles_manquent(): void
    {
        $town = \App\Models\Town::factory()->create(['title' => 'Ibanda']);
        $p = Precommande::factory()->sansCoordonnees()->create(['town_id' => $town->id]);

        $reponse = $this->get($this->lien($p))->assertStatus(200);

        $reponse->assertSee('Où livrer ?', false);
        $reponse->assertSee('name="adresse"', false);
        $reponse->assertSee('name="recipient_name"', false);
        $reponse->assertSee('name="recipient_phone"', false);

        // La commune est affichee, jamais ressaisie.
        $reponse->assertSee('Ibanda', false);
        $reponse->assertDontSee('name="town"', false);
        $reponse->assertDontSee('name="commune"', false);
    }

    public function test_le_formulaire_masque_les_coordonnees_deja_figees(): void
    {
        $p = Precommande::factory()->create([
            'adresse_delivery' => 'Avenue Kasa-Vubu 45',
            'recipient_name' => 'Josephine Mukendi',
        ]);

        $reponse = $this->get($this->lien($p))->assertStatus(200);

        $reponse->assertDontSee('name="adresse"', false);
        $reponse->assertDontSee('Avenue Kasa-Vubu 45', false);
        $reponse->assertSee('Ave******', false);
    }

    public function test_le_formulaire_propose_les_deux_moyens_de_paiement(): void
    {
        $p = Precommande::factory()->create();

        $this->get($this->lien($p))->assertStatus(200)
            ->assertSee('Comment payer ?', false)
            ->assertSee('value="mobile"', false)
            ->assertSee('value="cart"', false);
    }

    public function test_un_post_valide_fige_les_coordonnees_puis_initie(): void
    {
        $p = Precommande::factory()->sansCoordonnees()->create();

        $this->post($this->lienInitiation($p), [
            'adresse' => 'Avenue de la Democratie 1428',
            'street' => 'Avenue de la Democratie',
            'number_street' => '1428',
            'reference' => 'En face du marche',
            'recipient_name' => 'Josephine Mukendi',
            'recipient_phone' => '+243810000001',
            'method' => 'mobile',
            'phone' => '+243810000000',
        ])->assertStatus(200);

        $p->refresh();

        $this->assertSame('Avenue de la Democratie 1428', $p->adresse_delivery);
        $this->assertSame('Avenue de la Democratie', $p->street);
        $this->assertSame('1428', $p->number_street);
        $this->assertSame('En face du marche', $p->reference_adresse);
        $this->assertSame('Josephine Mukendi', $p->recipient_name);
        $this->assertSame('+243810000001', $p->recipient_phone);
        $this->assertSame('TEST-ORDER-1', $p->reference_paiement);
    }

    public function test_la_case_meme_numero_fait_payer_le_numero_du_destinataire(): void
    {
        $p = Precommande::factory()->sansCoordonnees()->create();

        $this->post($this->lienInitiation($p), [
            'adresse' => 'Avenue Test',
            'recipient_name' => 'Josephine Mukendi',
            'recipient_phone' => '+243810000001',
            'method' => 'mobile',
            'meme_numero' => '1',
        ])->assertStatus(200);

        Http::assertSent(fn ($request) => $request['phone'] === '+243810000001');
    }

    public function test_un_second_post_ne_reecrit_pas_des_coordonnees_figees(): void
    {
        // Un lien peut avoir ete transfere : celui qui l'a ne doit pas pouvoir
        // detourner une livraison deja renseignee.
        $p = Precommande::factory()->create([
            'adresse_delivery' => 'Avenue Kasa-Vubu 45',
            'recipient_name' => 'Josephine Mukendi',
            'recipient_phone' => '+243810000001',
        ]);

        $this->post($this->lienInitiation($p), [
            'adresse' => 'Chez le voleur',
            'recipient_name' => 'Voleur',
            'recipient_phone' => '+243819999999',
            'method' => 'mobile',
            'phone' => '+243810000000',
        ])->assertStatus(200);

        $p->refresh();

        $this->assertSame('Avenue Kasa-Vubu 45', $p->adresse_delivery);
        $this->assertSame('Josephine Mukendi', $p->recipient_name);
        $this->assertSame('+243810000001', $p->recipient_phone);
    }

    public function test_une_adresse_manquante_renvoie_au_formulaire_sans_rien_ecrire(): void
    {
        $p = Precommande::factory()->sansCoordonnees()->create();

        $this->from($this->lien($p))->post($this->lienInitiation($p), [
            'recipient_name' => 'Josephine Mukendi',
            'recipient_phone' => '+243810000001',
            'method' => 'mobile',
            'phone' => '+243810000000',
        ])->assertRedirect()->assertSessionHasErrors('adresse');

        $p->refresh();

        $this->assertNull($p->adresse_delivery);
        $this->assertNull($p->recipient_name);
        $this->assertNull($p->reference_paiement);

        Http::assertNothingSent();
    }

    public function test_une_methode_de_paiement_inconnue_est_refusee(): void
    {
        $p = Precommande::factory()->create();

        $this->from($this->lien($p))->post($this->lienInitiation($p), [
            'method' => 'bitcoin',
            'phone' => '+243810000000',
        ])->assertRedirect()->assertSessionHasErrors('method');

        Http::assertNothingSent();
    }

    public function test_la_carte_redirige_vers_l_url_de_la_passerelle(): void
    {
        $this->reponseFlexPay([
            'code' => 0,
            'orderNumber' => 'TEST-ORDER-CARTE',
            'url' => 'https://cardpayment.flexpay.cd/pay/xyz',
        ]);

        $p = Precommande::factory()->create(['total' => 5500]);

        $this->post($this->lienInitiation($p), ['method' => 'cart'])
            ->assertRedirect('https://cardpayment.flexpay.cd/pay/xyz');

        $this->assertSame('TEST-ORDER-CARTE', $p->fresh()->reference_paiement);
    }

    public function test_la_carte_sans_url_revient_au_formulaire(): void
    {
        $this->reponseFlexPay(['code' => 0, 'orderNumber' => 'TEST-ORDER-CARTE']);

        $p = Precommande::factory()->create(['total' => 5500]);

        $this->from($this->lien($p))->post($this->lienInitiation($p), ['method' => 'cart'])
            ->assertRedirect($this->lien($p))
            ->assertSessionHasErrors('method');
    }

    public function test_la_carte_n_exige_pas_de_numero_de_payeur(): void
    {
        $this->reponseFlexPay([
            'code' => 0, 'orderNumber' => 'TEST-ORDER-CARTE', 'url' => 'https://cardpayment.flexpay.cd/pay/xyz',
        ]);

        $p = Precommande::factory()->create(['total' => 5500]);

        $this->post($this->lienInitiation($p), ['method' => 'cart'])->assertRedirect();

        // L'application envoie la chaine vide en carte : on fait pareil.
        Http::assertSent(fn ($request) => $request['phone'] === '');
    }

    public function test_la_carte_sous_le_minimum_est_refusee(): void
    {
        // Controle reproduit a l'identique depuis l'application, devise
        // comprise : il ne la regarde pas, et on ne le corrige pas ici.
        $p = Precommande::factory()->create(['total' => 2]);

        $this->from($this->lien($p))->post($this->lienInitiation($p), ['method' => 'cart'])
            ->assertRedirect()
            ->assertSessionHasErrors(['method' => "Pour le paiement par cart le montant minimum c'est 2USD"]);

        Http::assertNothingSent();
    }

    public function test_un_telephone_invalide_en_mobile_money_ne_fait_pas_tomber_la_page(): void
    {
        // LibPhoneNumber renvoie l'exception de parsing au lieu de la lever :
        // isValidNumber() recoit alors le mauvais type et leve une TypeError.
        $p = Precommande::factory()->create();

        $this->from($this->lien($p))->post($this->lienInitiation($p), [
            'method' => 'mobile',
            'phone' => 'pas-un-numero',
        ])->assertRedirect()->assertSessionHasErrors('phone');

        Http::assertNothingSent();
    }

    public function test_le_montant_envoye_ignore_celui_de_la_requete(): void
    {
        $p = Precommande::factory()->create(['total' => 5500]);

        $this->post($this->lienInitiation($p), [
            'method' => 'mobile',
            'phone' => '+243810000000',
            'amount' => 1,
            'total' => 1,
        ])->assertStatus(200);

        Http::assertSent(fn ($request) => (float) $request['amount'] === 5500.0);
    }

    public function test_la_commune_envoyee_par_le_formulaire_est_ignoree(): void
    {
        // La commune a choisi la tranche de livraison, donc le total fige :
        // l'accepter laisserait payer un tarif du centre pour la peripherie.
        $ibanda = \App\Models\Town::factory()->create(['title' => 'Ibanda']);
        $autre = \App\Models\Town::factory()->create(['title' => 'Bagira']);

        $p = Precommande::factory()->sansCoordonnees()->create([
            'town_id' => $ibanda->id,
            'total' => 5500,
        ]);

        $this->post($this->lienInitiation($p), [
            'adresse' => 'Avenue Test',
            'recipient_name' => 'Josephine Mukendi',
            'recipient_phone' => '+243810000001',
            'method' => 'mobile',
            'phone' => '+243810000000',
            'town' => $autre->slug,
            'town_id' => $autre->id,
            'commune' => 'Bagira',
        ])->assertStatus(200);

        $p->refresh();

        $this->assertSame($ibanda->id, $p->town_id);
        $this->assertSame(5500.0, (float) $p->total);
    }
}
