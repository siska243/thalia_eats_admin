<?php

namespace Tests\Feature\Api;

use App\Models\Precommande;
use App\Models\Product;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le lien de paiement vu depuis le site.
 *
 * LienPaiementTest couvre la page Blade, conservee pour les liens deja emis.
 * Ce fichier-ci couvre les deux points d'entree d'API que consomme la page du
 * site — et surtout le fait que les memes regles s'y appliquent : une regle
 * qui ne vaudrait que sur un des deux chemins n'est pas une regle.
 */
class LienPaiementFrontTest extends TestCase
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
            'api.precommande.lien-paiement',
            $expiration ?: $p->expires_at,
            ['uid' => Cipher::Encrypt($p->id)],
        );
    }

    // --- La signature ---------------------------------------------------

    public function test_une_lecture_sans_signature_est_refusee(): void
    {
        $p = Precommande::factory()->create();

        $this->getJson('/api/precommandes/'.Cipher::Encrypt($p->id).'/lien-paiement')
            ->assertStatus(403);
    }

    public function test_une_lecture_dont_la_signature_est_alteree_est_refusee(): void
    {
        $p = Precommande::factory()->create();

        $this->getJson($this->lien($p).'X')->assertStatus(403);
    }

    public function test_un_paiement_sans_signature_est_refuse(): void
    {
        $p = Precommande::factory()->create();

        $this->postJson('/api/precommandes/'.Cipher::Encrypt($p->id).'/lien-paiement', [
            'method' => 'mobile', 'phone' => '+243810000000',
        ])->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_une_signature_emise_pour_une_autre_precommande_est_refusee(): void
    {
        $mienne = Precommande::factory()->create();
        $autre = Precommande::factory()->create();

        $forgee = str_replace(
            Cipher::Encrypt($autre->id),
            Cipher::Encrypt($mienne->id),
            $this->lien($autre)
        );

        $this->postJson($forgee, ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_une_seule_signature_couvre_la_lecture_et_le_paiement(): void
    {
        // Les deux routes partagent la meme URI et la signature de Laravel ne
        // couvre pas la methode HTTP : c'est ce qui permet au site de ne
        // transporter qu'un couple expires/signature.
        $p = Precommande::factory()->create(['total' => 5500]);
        $url = $this->lien($p);

        $this->getJson($url)->assertStatus(200);
        $this->postJson($url, ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(200);
    }

    public function test_une_signature_expiree_est_refusee(): void
    {
        $p = Precommande::factory()->create();
        $url = $this->lien($p, now()->addMinute());

        $this->travel(2)->minutes();

        $this->getJson($url)->assertStatus(403);
    }

    // --- Le lien remis au client ----------------------------------------

    public function test_le_lien_remis_au_client_pointe_sur_le_site_et_porte_la_signature(): void
    {
        config(['site.url' => 'https://thaliaeats.com']);

        $moi = User::factory()->create();
        Sanctum::actingAs($moi, ['precommande:lire']);

        $p = Precommande::factory()->create(['user_id' => $moi->id]);

        $lien = $this->getJson('/api/precommandes/'.Cipher::Encrypt($p->id))
            ->assertStatus(200)
            ->json('data.lien_paiement');

        $this->assertStringStartsWith(
            'https://thaliaeats.com/paiement/precommande/'.Cipher::Encrypt($p->id).'?',
            $lien
        );

        parse_str(parse_url($lien, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('expires', $query);
        $this->assertArrayHasKey('signature', $query);
    }

    public function test_la_chaine_de_requete_du_lien_valide_bien_l_appel_a_l_api(): void
    {
        // Le coeur de la solution : la signature est calculee sur l'URL d'API,
        // le lien du site n'en recopie que la chaine de requete, et elle suffit
        // a authentifier l'appel. Si ce test tombe, la page du site rend 403
        // chez tous les clients.
        $moi = User::factory()->create();
        Sanctum::actingAs($moi, ['precommande:lire']);

        $p = Precommande::factory()->create(['user_id' => $moi->id, 'total' => 5500]);

        $lien = $this->getJson('/api/precommandes/'.Cipher::Encrypt($p->id))
            ->json('data.lien_paiement');

        $query = parse_url($lien, PHP_URL_QUERY);
        $uid = Cipher::Encrypt($p->id);

        // Le client qui clique sur son lien n'a pas de session : on oublie
        // celle qu'a posee Sanctum::actingAs, sinon RefuserAgentSansAbility
        // refuserait une route qui ne declare aucune ability — et pour cause,
        // la signature EST l'autorisation.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/precommandes/'.$uid.'/lien-paiement?'.$query)
            ->assertStatus(200)
            ->assertJsonPath('data.total', 5500);
    }

    /**
     * Un vrai en-tete Authorization, jamais Sanctum::actingAs().
     *
     * actingAs() appelle shouldUse('sanctum') et change le garde par defaut
     * POUR LE PROCESSUS DE TEST. C'est la seule raison pour laquelle
     * $request->user() resoudrait ici : en production, le garde par defaut est
     * « web » (pilote session) et le groupe api n'a pas de StartSession, donc
     * aucun utilisateur n'est jamais resolu sur une route sans `auth:sanctum`.
     * Un test ecrit avec actingAs() passerait meme sans aucun controle : il
     * testerait actingAs, pas le garde.
     *
     * @param  array<int, string>  $abilities
     */
    private function jeton(array $abilities): string
    {
        return User::factory()->create()
            ->createToken('test', $abilities)
            ->plainTextToken;
    }

    public function test_un_jeton_d_assistant_ne_peut_pas_payer_par_le_lien(): void
    {
        // Le lien signe est remis au porteur du jeton, donc a l'assistant qui a
        // cree la pre-commande : sans controle, il pourrait faire sonner le
        // telephone du client pour un paiement qu'aucun humain n'a confirme.
        $p = Precommande::factory()->create(['total' => 5500]);

        $this->withHeader('Authorization', 'Bearer '.$this->jeton(\App\Enums\TokenAbility::agent()))
            ->postJson($this->lien($p), ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'jeton_assistant');

        Http::assertNothingSent();
        $this->assertNull($p->fresh()->reference_paiement);
    }

    public function test_un_jeton_d_assistant_peut_encore_lire_le_recapitulatif(): void
    {
        // Asymetrie deliberee : lire ce qu'on paie ne deplace pas d'argent, et
        // l'assistant doit pouvoir verifier la pre-commande qu'il a creee.
        $p = Precommande::factory()->create(['total' => 5500]);

        $this->withHeader('Authorization', 'Bearer '.$this->jeton(\App\Enums\TokenAbility::agent()))
            ->getJson($this->lien($p))
            ->assertStatus(200);
    }

    public function test_un_client_connecte_au_site_peut_payer_par_le_lien(): void
    {
        // Le site envoie « Authorization » des qu'un jeton traine. Un jeton
        // applicatif porte « * » et doit passer : la page ne casse pas pour un
        // client connecte.
        $p = Precommande::factory()->create(['total' => 5500]);

        $this->withHeader('Authorization', 'Bearer '.$this->jeton(['*']))
            ->postJson($this->lien($p), ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(200);
    }

    public function test_un_visiteur_sans_jeton_paie_sans_entrave(): void
    {
        // Le cas normal : le client qui clique sur son lien n'a aucune session.
        $p = Precommande::factory()->create(['total' => 5500]);

        $this->postJson($this->lien($p), ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(200);
    }

    // --- Le recapitulatif -------------------------------------------------

    public function test_le_recapitulatif_donne_les_lignes_les_frais_et_le_total(): void
    {
        $town = Town::factory()->create(['title' => 'Ibanda']);
        $p = Precommande::factory()->create([
            'town_id' => $town->id,
            'sous_total' => 3000,
            'frais_livraison' => 2000,
            'service_price' => 500,
            'total' => 5500,
        ]);

        $produit = Product::factory()->create(['title' => 'Poulet moambe']);
        $p->products()->create(['product_id' => $produit->id, 'quantity' => 2, 'price' => 1500]);

        $this->getJson($this->lien($p))
            ->assertStatus(200)
            ->assertJsonPath('data.reference', $p->refernce)
            ->assertJsonPath('data.commune', 'Ibanda')
            ->assertJsonPath('data.sous_total', 3000)
            ->assertJsonPath('data.frais_livraison', 2000)
            ->assertJsonPath('data.service_price', 500)
            ->assertJsonPath('data.total', 5500)
            ->assertJsonPath('data.produits.0.title', 'Poulet moambe')
            ->assertJsonPath('data.produits.0.quantity', 2);
    }

    public function test_le_recapitulatif_masque_des_coordonnees_deja_figees(): void
    {
        // Un lien se transfere et se journalise : le porteur n'a pas a
        // apprendre ou et chez qui livrer.
        $p = Precommande::factory()->create([
            'adresse_delivery' => 'Avenue Kasa-Vubu 45',
            'recipient_name' => 'Josephine Mukendi',
        ]);

        $reponse = $this->getJson($this->lien($p))->assertStatus(200);

        $reponse->assertJsonPath('data.coordonnees_figees', true);
        $reponse->assertJsonPath('data.coordonnees.adresse', 'Ave******');
        $reponse->assertJsonPath('data.coordonnees.destinataire.name', 'Jos******');
        $reponse->assertDontSee('Avenue Kasa-Vubu 45', false);
        $reponse->assertDontSee('Josephine Mukendi', false);
    }

    public function test_le_recapitulatif_annonce_des_coordonnees_a_saisir(): void
    {
        $p = Precommande::factory()->sansCoordonnees()->create();

        $this->getJson($this->lien($p))
            ->assertStatus(200)
            ->assertJsonPath('data.coordonnees_figees', false)
            ->assertJsonPath('data.coordonnees', null);
    }

    public function test_le_recapitulatif_ne_rend_jamais_la_commune_comme_un_champ(): void
    {
        $town = Town::factory()->create(['title' => 'Ibanda']);
        $p = Precommande::factory()->sansCoordonnees()->create(['town_id' => $town->id]);

        $reponse = $this->getJson($this->lien($p))->assertStatus(200);

        $reponse->assertJsonPath('data.commune', 'Ibanda');
        // Ni identifiant ni slug : rien que la page puisse renvoyer.
        $reponse->assertJsonMissingPath('data.town_id');
        $reponse->assertJsonMissingPath('data.town');
    }

    public function test_le_recapitulatif_previent_quand_la_carte_est_sous_le_minimum(): void
    {
        $sous = Precommande::factory()->create(['total' => 2]);
        $au_dessus = Precommande::factory()->create(['total' => 5500]);

        $this->getJson($this->lien($sous))->assertJsonPath('data.carte_disponible', false);
        $this->getJson($this->lien($au_dessus))->assertJsonPath('data.carte_disponible', true);
    }

    // --- Les etats d'indisponibilite -------------------------------------

    public function test_une_precommande_expiree_repond_410_avec_sa_raison(): void
    {
        $p = Precommande::factory()->expiree()->create();

        $this->getJson($this->lien($p, now()->addHour()))
            ->assertStatus(410)
            ->assertJsonPath('error', 'precommande_expiree');
    }

    public function test_une_precommande_deja_payee_repond_410_avec_sa_raison(): void
    {
        $p = Precommande::factory()->payee()->create();

        $this->getJson($this->lien($p, now()->addHour()))
            ->assertStatus(410)
            ->assertJsonPath('error', 'precommande_deja_payee');
    }

    public function test_un_paiement_sur_une_precommande_expiree_n_appelle_pas_la_passerelle(): void
    {
        $p = Precommande::factory()->expiree()->create();

        $this->postJson($this->lien($p, now()->addHour()), [
            'method' => 'mobile', 'phone' => '+243810000000',
        ])->assertStatus(410);

        Http::assertNothingSent();
    }

    public function test_un_uid_illisible_repond_404(): void
    {
        $p = Precommande::factory()->create();

        // Signature valide, uid illisible : la signature autorise l'acces, elle
        // ne garantit pas que la pre-commande existe.
        $url = URL::temporarySignedRoute(
            'api.precommande.lien-paiement',
            $p->expires_at,
            ['uid' => 'nimportequoi'],
        );

        $this->getJson($url)->assertStatus(404);
    }

    // --- Le paiement ------------------------------------------------------

    public function test_un_premier_paiement_fige_les_coordonnees_puis_initie(): void
    {
        $p = Precommande::factory()->sansCoordonnees()->create();

        $this->postJson($this->lien($p), [
            'adresse' => 'Avenue de la Democratie 1428',
            'street' => 'Avenue de la Democratie',
            'number_street' => '1428',
            'reference' => 'En face du marche',
            'recipient_name' => 'Josephine Mukendi',
            'recipient_phone' => '+243810000001',
            'method' => 'mobile',
            'phone' => '+243810000000',
        ])->assertStatus(200)->assertJsonPath('data.method', 'mobile');

        $p->refresh();

        $this->assertSame('Avenue de la Democratie 1428', $p->adresse_delivery);
        $this->assertSame('Avenue de la Democratie', $p->street);
        $this->assertSame('1428', $p->number_street);
        $this->assertSame('En face du marche', $p->reference_adresse);
        $this->assertSame('Josephine Mukendi', $p->recipient_name);
        $this->assertSame('+243810000001', $p->recipient_phone);
        $this->assertSame('TEST-ORDER-1', $p->reference_paiement);
    }

    public function test_un_second_paiement_ne_reecrit_pas_des_coordonnees_figees(): void
    {
        $p = Precommande::factory()->create([
            'adresse_delivery' => 'Avenue Kasa-Vubu 45',
            'recipient_name' => 'Josephine Mukendi',
            'recipient_phone' => '+243810000001',
        ]);

        $this->postJson($this->lien($p), [
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

    public function test_la_commune_envoyee_par_le_formulaire_est_ignoree(): void
    {
        $ibanda = Town::factory()->create(['title' => 'Ibanda']);
        $bagira = Town::factory()->create(['title' => 'Bagira']);

        $p = Precommande::factory()->sansCoordonnees()->create([
            'town_id' => $ibanda->id,
            'frais_livraison' => 2000,
            'total' => 5500,
        ]);

        $this->postJson($this->lien($p), [
            'adresse' => 'Avenue Test',
            'recipient_name' => 'Josephine Mukendi',
            'recipient_phone' => '+243810000001',
            'method' => 'mobile',
            'phone' => '+243810000000',
            'town' => $bagira->slug,
            'town_id' => $bagira->id,
            'commune' => 'Bagira',
            'frais_livraison' => 0,
        ])->assertStatus(200);

        $p->refresh();

        $this->assertSame($ibanda->id, $p->town_id);
        $this->assertSame(2000.0, (float) $p->frais_livraison);
        $this->assertSame(5500.0, (float) $p->total);
    }

    public function test_le_montant_envoye_est_le_total_fige_pas_celui_de_la_requete(): void
    {
        // Le point le plus important du lot : la production a deja connu une
        // faille ou le prix venait du client.
        $p = Precommande::factory()->create(['total' => 5500]);

        $this->postJson($this->lien($p), [
            'method' => 'mobile',
            'phone' => '+243810000000',
            'amount' => 1,
            'total' => 1,
            'sous_total' => 1,
        ])->assertStatus(200);

        Http::assertSent(fn ($request) => (float) $request['amount'] === 5500.0);
    }

    public function test_une_adresse_manquante_repond_422_sans_rien_ecrire(): void
    {
        $p = Precommande::factory()->sansCoordonnees()->create();

        $this->postJson($this->lien($p), [
            'recipient_name' => 'Josephine Mukendi',
            'recipient_phone' => '+243810000001',
            'method' => 'mobile',
            'phone' => '+243810000000',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'coordonnees_invalides')
            ->assertJsonPath('errors.adresse.0', 'L\'adresse de livraison est obligatoire.');

        $p->refresh();

        $this->assertNull($p->adresse_delivery);
        $this->assertNull($p->recipient_name);
        $this->assertNull($p->reference_paiement);

        Http::assertNothingSent();
    }

    public function test_un_telephone_invalide_ne_fait_pas_tomber_la_requete(): void
    {
        // LibPhoneNumber renvoyait l'exception de parsing au lieu de la lever :
        // isValidNumber() recevait le mauvais type et levait une TypeError.
        $p = Precommande::factory()->create();

        $this->postJson($this->lien($p), [
            'method' => 'mobile',
            'phone' => 'pas-un-numero',
        ])->assertStatus(400)->assertJsonPath('error', 'telephone_invalide');

        Http::assertNothingSent();
    }

    public function test_la_case_meme_numero_fait_payer_le_numero_du_destinataire(): void
    {
        $p = Precommande::factory()->create(['recipient_phone' => '+243810000001']);

        $this->postJson($this->lien($p), [
            'method' => 'mobile',
            'meme_numero' => true,
        ])->assertStatus(200);

        Http::assertSent(fn ($request) => $request['phone'] === '+243810000001');
    }

    public function test_une_methode_inconnue_est_refusee(): void
    {
        $p = Precommande::factory()->create();

        $this->postJson($this->lien($p), ['method' => 'bitcoin'])
            ->assertStatus(400)
            ->assertJsonPath('error', 'methode_invalide');

        Http::assertNothingSent();
    }

    public function test_la_carte_sous_le_minimum_est_refusee_avec_le_message_de_l_application(): void
    {
        // Controle reproduit a l'identique depuis l'application, devise
        // comprise : il ne la regarde pas, et on ne le corrige pas ici.
        $p = Precommande::factory()->create(['total' => 2]);

        $this->postJson($this->lien($p), ['method' => 'cart'])
            ->assertStatus(400)
            ->assertJsonPath('error', 'minimum_carte')
            ->assertJsonPath('message', "Pour le paiement par cart le montant minimum c'est 2USD");

        Http::assertNothingSent();
    }

    public function test_la_carte_rend_l_url_de_la_passerelle(): void
    {
        $this->reponseFlexPay([
            'code' => 0,
            'orderNumber' => 'TEST-ORDER-CARTE',
            'url' => 'https://cardpayment.flexpay.cd/pay/xyz',
        ]);

        $p = Precommande::factory()->create(['total' => 5500]);

        $this->postJson($this->lien($p), ['method' => 'cart'])
            ->assertStatus(200)
            ->assertJsonPath('data.method', 'cart')
            ->assertJsonPath('data.url', 'https://cardpayment.flexpay.cd/pay/xyz');

        $this->assertSame('TEST-ORDER-CARTE', $p->fresh()->reference_paiement);
    }

    public function test_la_carte_sans_url_le_dit_au_lieu_de_rediriger_vers_le_vide(): void
    {
        $this->reponseFlexPay(['code' => 0, 'orderNumber' => 'TEST-ORDER-CARTE']);

        $p = Precommande::factory()->create(['total' => 5500]);

        $this->postJson($this->lien($p), ['method' => 'cart'])
            ->assertStatus(400)
            ->assertJsonPath('error', 'carte_indisponible');
    }

    public function test_la_carte_n_exige_pas_de_numero_de_payeur(): void
    {
        $this->reponseFlexPay([
            'code' => 0, 'orderNumber' => 'TEST-ORDER-CARTE', 'url' => 'https://cardpayment.flexpay.cd/pay/xyz',
        ]);

        $p = Precommande::factory()->create(['total' => 5500]);

        $this->postJson($this->lien($p), ['method' => 'cart'])->assertStatus(200);

        // L'application envoie la chaine vide en carte : on fait pareil.
        Http::assertSent(fn ($request) => $request['phone'] === '');
    }

    public function test_un_refus_de_la_passerelle_est_rendu_au_client(): void
    {
        $this->reponseFlexPay(['code' => 1, 'message' => 'Solde insuffisant']);

        $p = Precommande::factory()->create(['total' => 5500]);

        $this->postJson($this->lien($p), ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(400)
            ->assertJsonPath('error', 'paiement_refuse')
            ->assertJsonPath('message', 'Solde insuffisant');

        $this->assertNull($p->fresh()->reference_paiement);
    }

    public function test_une_seconde_initiation_immediate_est_refusee(): void
    {
        // Sans ce garde, le telephone du client sonne deux fois pour la meme
        // commande, et s'il confirme la premiere sollicitation la reference
        // enregistree n'est plus celle qui a ete payee.
        $p = Precommande::factory()->create(['total' => 5500]);
        $url = $this->lien($p);

        $this->postJson($url, ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(200);

        $this->postJson($url, ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(400)
            ->assertJsonPath('error', 'paiement_deja_initie')
            ->assertJsonPath('message', 'Un paiement vient d\'être lancé pour cette commande. Regardez votre téléphone et validez la demande reçue, ou patientez quelques minutes avant de réessayer.');

        // Une seule sollicitation est partie.
        Http::assertSentCount(1);
    }

    public function test_une_relance_apres_le_delai_est_acceptee_sans_ecraser_la_reference(): void
    {
        $p = Precommande::factory()->create(['total' => 5500]);
        $url = $this->lien($p);

        $this->postJson($url, ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(200);

        $this->travel(config('precommande.delai_relance_paiement_minutes') + 1)->minutes();

        $this->reponseFlexPay(['code' => 0, 'orderNumber' => 'TEST-ORDER-2']);

        $this->postJson($url, ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(200);

        // La reference de la PREMIERE initiation reussie tient : si le client
        // confirme finalement la sollicitation d'avant, la base ne pointe pas
        // vers un paiement qui n'a jamais eu lieu.
        $this->assertSame('TEST-ORDER-1', $p->fresh()->reference_paiement);
    }

    public function test_la_carte_n_est_jamais_bloquee_par_le_garde_de_relance(): void
    {
        // Le client part sur la page FlexPay, fait retour arriere, hesite, la
        // redirection echoue — et reclique. La carte se regle sur la page de la
        // passerelle : aucun telephone ne sonne, rien a proteger, et lui
        // repondre « regardez votre telephone » serait une consigne absurde
        // doublee d'une commande perdue.
        $this->reponseFlexPay([
            'code' => 0, 'orderNumber' => 'TEST-ORDER-CARTE', 'url' => 'https://cardpayment.flexpay.cd/pay/xyz',
        ]);

        $p = Precommande::factory()->create(['total' => 5500]);
        $url = $this->lien($p);

        $this->postJson($url, ['method' => 'cart'])->assertStatus(200);

        $this->postJson($url, ['method' => 'cart'])
            ->assertStatus(200)
            ->assertJsonPath('data.url', 'https://cardpayment.flexpay.cd/pay/xyz');
    }

    public function test_un_essai_par_carte_ne_bloque_pas_un_mobile_money_suivant(): void
    {
        // Le meme defaut que carte -> carte, atteint par l'autre porte :
        // l'armement du garde etait indifferent a la methode alors que sa
        // lecture est reservee au mobile money. Le client cliquait « Payer par
        // carte », arrivait sur la page FlexPay, hesitait, revenait, choisissait
        // le mobile money — et se faisait refuser cinq minutes avec « regardez
        // votre telephone », pour un combine qui n'avait jamais sonne.
        $this->reponseFlexPay([
            'code' => 0, 'orderNumber' => 'TEST-ORDER-CARTE', 'url' => 'https://cardpayment.flexpay.cd/pay/xyz',
        ]);

        $p = Precommande::factory()->create(['total' => 5500]);
        $url = $this->lien($p);

        $this->postJson($url, ['method' => 'cart'])->assertStatus(200);

        // Un essai carte ne doit rien armer : le garde ne lit que le mobile.
        $this->assertNull($p->fresh()->paiement_initie_a);

        $this->reponseFlexPay(['code' => 0, 'orderNumber' => 'TEST-ORDER-MOBILE']);

        $this->postJson($url, ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(200)
            ->assertJsonPath('data.method', 'mobile');
    }

    public function test_le_mobile_en_vol_ne_bloque_pas_la_carte(): void
    {
        // La quatrieme sequence, et la seule qui n'avait pas de test. C'est
        // precisement cette asymetrie que la branche a deja cassee deux fois :
        // le garde ne surveille que le mobile money, il ne doit donc jamais
        // refuser une carte, meme une sollicitation mobile encore en vol.
        $p = Precommande::factory()->create(['total' => 5500]);
        $url = $this->lien($p);

        $this->postJson($url, ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(200);

        $this->reponseFlexPay([
            'code' => 0, 'orderNumber' => 'TEST-ORDER-CARTE', 'url' => 'https://cardpayment.flexpay.cd/pay/xyz',
        ]);

        $this->postJson($url, ['method' => 'cart'])
            ->assertStatus(200)
            ->assertJsonPath('data.url', 'https://cardpayment.flexpay.cd/pay/xyz');
    }

    public function test_une_initiation_sans_order_number_arme_quand_meme_le_garde(): void
    {
        // L'unique etat du garde ne doit pas etre un champ que la passerelle
        // peut ne pas fournir : sinon il devient aveugle exactement quand il
        // sert, et le double appel redevient possible.
        $this->reponseFlexPay(['code' => 0, 'message' => 'Transaction initiee']);

        $p = Precommande::factory()->create(['total' => 5500]);
        $url = $this->lien($p);

        $this->postJson($url, ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(200);

        $this->assertNull($p->fresh()->reference_paiement);
        $this->assertNotNull($p->fresh()->paiement_initie_a);

        $this->postJson($url, ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(400)
            ->assertJsonPath('error', 'paiement_deja_initie');

        Http::assertSentCount(1);
    }

    public function test_le_numero_du_payeur_est_masque_dans_la_reponse(): void
    {
        $p = Precommande::factory()->create();

        $this->postJson($this->lien($p), ['method' => 'mobile', 'phone' => '+243810000000'])
            ->assertStatus(200)
            ->assertJsonPath('data.phone', '+24381******')
            ->assertDontSee('+243810000000', false);
    }
}
