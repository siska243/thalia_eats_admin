<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\Precommande;
use App\Models\Product;
use App\Models\Status;
use App\Services\ConversionPrecommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversionPrecommandeTest extends TestCase
{
    use RefreshDatabase;

    private string $journal_paiement;

    protected function setUp(): void
    {
        parent::setUp();
        Status::factory()->create(['id' => 2]);

        // Toute la securite residuelle du mode ouvert repose sur le fait que
        // ce journal soit ecrit puis lu : on le detourne vers un fichier de
        // test pour pouvoir l'affirmer, pas seulement l'esperer.
        // Chemin unique par instance : deux sessions travaillent dans ce
        // meme checkout, et un chemin fixe ferait que chaque run supprime
        // le fichier de l'autre — les assertions qui epinglent les capteurs
        // echoueraient alors sans qu'aucun capteur ne soit casse.
        $this->journal_paiement = storage_path('logs/paiement-test-'.getmypid().'-'.uniqid().'.log');
        @unlink($this->journal_paiement);

        config(['logging.channels.paiement' => [
            'driver' => 'single',
            'path' => $this->journal_paiement,
            'level' => 'debug',
        ]]);
    }

    protected function tearDown(): void
    {
        @unlink($this->journal_paiement);
        parent::tearDown();
    }

    private function journal(): string
    {
        return file_exists($this->journal_paiement)
            ? file_get_contents($this->journal_paiement)
            : '';
    }

    private function precommandeAvecLignes(): Precommande
    {
        $precommande = Precommande::factory()->create(['total' => 5500]);
        $produit = Product::factory()->create(['price' => 9999]);

        $precommande->products()->create([
            'product_id' => $produit->id,
            'quantity' => 2,
            'price' => 1500,
        ]);

        return $precommande->fresh('products');
    }

    public function test_la_commande_produite_est_au_statut_2_et_payee(): void
    {
        $commande = app(ConversionPrecommande::class)->convertir($this->precommandeAvecLignes());

        $this->assertSame(2, (int) $commande->status_id);
        $this->assertNotNull($commande->paied_at);
    }

    public function test_elle_porte_le_total_fige_et_non_le_prix_courant(): void
    {
        $commande = app(ConversionPrecommande::class)->convertir($this->precommandeAvecLignes());

        $this->assertSame(5500.0, (float) $commande->global_price);
        $this->assertSame(1500.0, (float) $commande->product->first()->price);
    }

    public function test_elle_reprend_le_destinataire_et_l_adresse(): void
    {
        $precommande = $this->precommandeAvecLignes();

        $commande = app(ConversionPrecommande::class)->convertir($precommande);

        $this->assertSame($precommande->recipient_name, $commande->recipient_name);
        $this->assertSame($precommande->recipient_phone, $commande->recipient_phone);
        $this->assertSame($precommande->adresse_delivery, $commande->adresse_delivery);
        $this->assertSame((int) $precommande->town_id, (int) $commande->town_id);
    }

    public function test_la_precommande_est_marquee_payee_et_liee(): void
    {
        $precommande = $this->precommandeAvecLignes();

        $commande = app(ConversionPrecommande::class)->convertir($precommande);

        $precommande->refresh();

        $this->assertSame(Precommande::STATUT_PAYEE, $precommande->status);
        $this->assertSame((int) $commande->id, (int) $precommande->commande_id);
        $this->assertNotNull($precommande->paied_at);
    }

    public function test_un_produit_present_deux_fois_donne_une_seule_ligne(): void
    {
        $precommande = Precommande::factory()->create(['total' => 5500]);
        $produit = Product::factory()->create(['price' => 1500]);

        // Le meme uid poste deux fois : deux lignes de precommande.
        foreach ([2, 3] as $quantite) {
            $precommande->products()->create([
                'product_id' => $produit->id,
                'quantity' => $quantite,
                'price' => 1500,
            ]);
        }

        $commande = app(ConversionPrecommande::class)->convertir($precommande->fresh('products'));

        $lignes = $commande->product;

        $this->assertCount(1, $lignes);
        $this->assertSame(5.0, (float) $lignes->first()->quantity);
        $this->assertSame(1500.0, (float) $lignes->first()->price);
    }

    public function test_la_reference_de_la_commande_est_un_entier_nu(): void
    {
        // Sinon elle entrerait en collision avec l'espace des pré-commandes.
        $commande = app(ConversionPrecommande::class)->convertir($this->precommandeAvecLignes());

        $this->assertTrue(ctype_digit((string) $commande->refernce));
    }

    public function test_convertir_deux_fois_ne_cree_pas_deux_commandes(): void
    {
        $precommande = $this->precommandeAvecLignes();

        app(ConversionPrecommande::class)->convertir($precommande);
        $seconde = app(ConversionPrecommande::class)->convertirSiPossible($precommande->refernce);

        $this->assertNull($seconde);
        $this->assertSame(1, Commande::query()->count());
    }

    public function test_une_reference_de_commande_ordinaire_ne_convertit_rien(): void
    {
        $this->assertNull(app(ConversionPrecommande::class)->convertirSiPossible('1038'));
    }

    public function test_une_precommande_expiree_se_convertit_quand_meme_si_elle_est_payee(): void
    {
        // Le client a payé juste avant l'expiration ; le webhook arrive après.
        // Refuser ici encaisserait sans livrer.
        $precommande = Precommande::factory()->expiree()->create();
        Product::factory()->create();

        $commande = app(ConversionPrecommande::class)->convertirSiPossible($precommande->refernce);

        $this->assertNotNull($commande);
    }

    public function test_le_webhook_d_une_commande_ordinaire_est_inchange(): void
    {
        // La table status_payements a pour colonnes reelles code/name/is_paid
        // (pas title/slug) ; le code '0' correspond au statut renvoye par le
        // Http::fake ci-dessous, sur lequel le controleur fait sa recherche.
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['code' => '0'],
            ['name' => 'En attente', 'is_paid' => false, 'is_default' => true]
        );

        $commande = Commande::query()->create([
            'refernce' => '4242',
            'user_id' => \App\Models\User::factory()->create()->id,
            'status_id' => 5,
            'global_price' => 7000,
        ]);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok', 'transaction' => ['status' => '0'],
        ], 200)]);

        $response = $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => '4242',
            'orderNumber' => 'ORD-1',
            'amount' => 7000,
            'amountCustomer' => 7000,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000000',
            'provider_reference' => 'PROV-1',
        ]);

        // L'enveloppe de succes attendue : sans elle, un garde qui renvoie
        // toujours "reference inconnue" (par ex. `if (true)` a la place du
        // garde reel) passerait quand meme sur le seul critere de comptage.
        $response->assertStatus(201);
        $response->assertJson(['title' => 'Crée', 'message' => 'La resource a été crée']);

        // Une ligne payements a bien ete ecrite pour CETTE commande : preuve
        // que le chemin Payement::updateOrCreate($order->id, ...) a ete
        // atteint, et non court-circuite par le nouveau garde.
        $this->assertDatabaseHas('payements', ['commande_id' => $commande->id]);

        // Aucune Commande supplémentaire n'a été créée par l'extension.
        $this->assertSame(1, Commande::query()->count());
        $this->assertSame('4242', Commande::query()->first()->refernce);
    }

    public function test_le_webhook_d_une_commande_ordinaire_payee_met_a_jour_son_statut(): void
    {
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['code' => '0'],
            ['name' => 'Transaction traitée avec succès', 'is_paid' => true]
        );

        $commande = Commande::query()->create([
            'refernce' => '4243',
            'user_id' => \App\Models\User::factory()->create()->id,
            'status_id' => 5,
            'global_price' => 7000,
        ]);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok', 'transaction' => ['status' => '0'],
        ], 200)]);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => '4243',
            'orderNumber' => 'ORD-2',
            'amount' => 7000,
            'amountCustomer' => 7000,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000001',
            'provider_reference' => 'PROV-2',
        ]);

        $commande->refresh();

        $this->assertSame(2, (int) $commande->status_id);
        $this->assertNotNull($commande->paied_at);
    }

    public function test_le_webhook_convertit_une_precommande_payee(): void
    {
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['code' => '0'],
            ['name' => 'Transaction traitée avec succès', 'is_paid' => true]
        );

        $precommande = $this->precommandeAvecLignes();

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok',
            'transaction' => ['status' => '0', 'reference' => $precommande->refernce, 'amount' => 5500],
        ], 200)]);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $precommande->refernce,
            'orderNumber' => 'ORD-P1',
            'amount' => 5500,
            'amountCustomer' => 5500,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000002',
            'provider_reference' => 'PROV-P1',
        ]);

        $this->assertSame(1, Commande::query()->count());
        $commande = Commande::query()->first();
        $this->assertDatabaseHas('payements', ['commande_id' => $commande->id]);

        // La meme livraison rejouee : le webhook ne trouve plus de
        // pre-commande en_attente (elle est deja payee) et ne cree pas de
        // seconde Commande.
        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $precommande->refernce,
            'orderNumber' => 'ORD-P1',
            'amount' => 5500,
            'amountCustomer' => 5500,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000002',
            'provider_reference' => 'PROV-P1',
        ]);

        $this->assertSame(1, Commande::query()->count());
    }

    public function test_le_webhook_ne_convertit_pas_une_precommande_non_payee(): void
    {
        // code 2 : "Paiement en attente", non paye — c'est le cas majoritaire
        // d'un paiement mobile money lance mais pas encore confirme par le
        // client sur son telephone. Convertir ici encaisserait rien et
        // ferait cuisiner un repas non paye.
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['code' => '2'],
            ['name' => 'Paiement en attente', 'is_paid' => false, 'is_default' => true]
        );

        $precommande = $this->precommandeAvecLignes();

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok', 'transaction' => ['status' => '2'],
        ], 200)]);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $precommande->refernce,
            'orderNumber' => 'ORD-P2',
            'amount' => 5500,
            'amountCustomer' => 5500,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000003',
            'provider_reference' => 'PROV-P2',
        ]);

        $this->assertSame(0, Commande::query()->count());

        $precommande->refresh();
        $this->assertSame(Precommande::STATUT_EN_ATTENTE, $precommande->status);
    }

    /**
     * Une transaction reellement payee, mais qui appartient a quelqu'un
     * d'autre. Avant le garde, il suffisait de la presenter avec la
     * reference de la commande de son choix (refernce = 1000 + id, donc
     * enumerable) pour la faire marquer payee.
     */
    public function test_le_webhook_refuse_une_reference_qui_n_est_pas_celle_de_la_transaction(): void
    {
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['code' => '0'],
            ['name' => 'Transaction traitée avec succès', 'is_paid' => true]
        );

        $commande = Commande::query()->create([
            'refernce' => '4244',
            'user_id' => \App\Models\User::factory()->create()->id,
            'status_id' => 5,
            'global_price' => 7000,
        ]);

        // La passerelle rattache la transaction a la commande 9999 ;
        // l'appelant, lui, annonce 4244.
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok',
            'transaction' => ['status' => '0', 'reference' => '9999'],
        ], 200)]);

        $response = $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => '4244',
            'orderNumber' => 'ORD-VOLE',
            'amount' => 7000,
            'amountCustomer' => 7000,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000010',
            'provider_reference' => 'PROV-VOLE',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'reference_incoherente']);

        $commande->refresh();

        $this->assertSame(5, (int) $commande->status_id);
        $this->assertNull($commande->paied_at);
        $this->assertDatabaseMissing('payements', ['commande_id' => $commande->id]);
    }

    public function test_le_webhook_refuse_une_precommande_dont_la_transaction_designe_autre_chose(): void
    {
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['code' => '0'],
            ['name' => 'Transaction traitée avec succès', 'is_paid' => true]
        );

        $precommande = $this->precommandeAvecLignes();

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok',
            'transaction' => ['status' => '0', 'reference' => 'P-AUTRECHOSE'],
        ], 200)]);

        $response = $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $precommande->refernce,
            'orderNumber' => 'ORD-VOLE-2',
            'amount' => 5500,
            'amountCustomer' => 5500,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000011',
            'provider_reference' => 'PROV-VOLE-2',
        ]);

        // Sans ce code, l'assertion serait satisfaite par n'importe quel
        // autre garde renvoyant 400 — notamment celui du montant.
        $response->assertStatus(400);
        $response->assertJson(['error' => 'reference_incoherente']);

        $this->assertSame(0, Commande::query()->count());

        $precommande->refresh();
        $this->assertSame(Precommande::STATUT_EN_ATTENTE, $precommande->status);
    }

    public function test_le_webhook_accepte_une_commande_ordinaire_dont_la_reference_concorde(): void
    {
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['code' => '0'],
            ['name' => 'Transaction traitée avec succès', 'is_paid' => true]
        );

        $commande = Commande::query()->create([
            'refernce' => '4245',
            'user_id' => \App\Models\User::factory()->create()->id,
            'status_id' => 5,
            'global_price' => 7000,
        ]);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok',
            'transaction' => ['status' => '0', 'reference' => '4245'],
        ], 200)]);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => '4245',
            'orderNumber' => 'ORD-OK',
            'amount' => 7000,
            'amountCustomer' => 7000,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000012',
            'provider_reference' => 'PROV-OK',
        ])->assertStatus(201);

        $commande->refresh();

        $this->assertSame(2, (int) $commande->status_id);
        $this->assertNotNull($commande->paied_at);
        $this->assertDatabaseHas('payements', ['commande_id' => $commande->id]);
    }

    /**
     * Le chemin pre-commande n'a pas encore de trafic de production : il peut
     * donc echouer ferme. Sans reference rattachee par la passerelle, rien ne
     * prouve que cette transaction concerne CETTE pre-commande.
     */
    public function test_le_webhook_refuse_une_precommande_si_la_transaction_n_a_pas_de_reference(): void
    {
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['code' => '0'],
            ['name' => 'Transaction traitée avec succès', 'is_paid' => true]
        );

        $precommande = $this->precommandeAvecLignes();

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok', 'transaction' => ['status' => '0'],
        ], 200)]);

        $response = $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $precommande->refernce,
            'orderNumber' => 'ORD-SANS-REF',
            'amount' => 5500,
            'amountCustomer' => 5500,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000013',
            'provider_reference' => 'PROV-SANS-REF',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'reference_non_verifiable']);

        $this->assertSame(0, Commande::query()->count());

        $precommande->refresh();
        $this->assertSame(Precommande::STATUT_EN_ATTENTE, $precommande->status);
    }

    /**
     * L'asymetrie, epinglee : une commande ordinaire continue de fonctionner
     * meme si FlexPay ne renvoie pas le champ. On journalise et on apprend,
     * plutot que de casser des paiements en production sur une hypothese.
     */
    public function test_une_commande_ordinaire_passe_encore_si_la_transaction_n_a_pas_de_reference(): void
    {
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['code' => '0'],
            ['name' => 'Transaction traitée avec succès', 'is_paid' => true]
        );

        $commande = Commande::query()->create([
            'refernce' => '4246',
            'user_id' => \App\Models\User::factory()->create()->id,
            'status_id' => 5,
            'global_price' => 7000,
        ]);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok', 'transaction' => ['status' => '0'],
        ], 200)]);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => '4246',
            'orderNumber' => 'ORD-LEGACY',
            'amount' => 7000,
            'amountCustomer' => 7000,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000014',
            'provider_reference' => 'PROV-LEGACY',
        ])->assertStatus(201);

        $commande->refresh();

        $this->assertSame(2, (int) $commande->status_id);
        $this->assertDatabaseHas('payements', ['commande_id' => $commande->id]);

        // Le capteur : c'est lui qui dira, en production, si le mode ouvert
        // s'active vraiment et donc s'il peut etre ferme.
        $this->assertStringContainsString('coherence non etablie (mode ouvert)', $this->journal());
        $this->assertStringContainsString('ORD-LEGACY', $this->journal());
    }

    /**
     * Fix 1 verrouillait l'identite de la transaction, pas son montant. Un
     * attaquant pouvait donc payer 100 CDF sa PROPRE pre-commande minuscule,
     * puis presenter cette transaction — parfaitement coherente — pour en
     * faire convertir, cuisiner et livrer une de 5500.
     */
    public function test_le_webhook_refuse_une_precommande_dont_le_montant_verifie_ne_correspond_pas(): void
    {
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['code' => '0'],
            ['name' => 'Transaction traitée avec succès', 'is_paid' => true]
        );

        $precommande = $this->precommandeAvecLignes();

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok',
            'transaction' => ['status' => '0', 'reference' => $precommande->refernce, 'amount' => 100],
        ], 200)]);

        // Le montant ANNONCE est le bon : c'est bien celui de la transaction
        // verifiee qui doit trancher, jamais celui de la requete.
        $response = $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $precommande->refernce,
            'orderNumber' => 'ORD-PETIT',
            'amount' => 5500,
            'amountCustomer' => 5500,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000020',
            'provider_reference' => 'PROV-PETIT',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'montant_incoherent']);

        $this->assertSame(0, Commande::query()->count());

        $precommande->refresh();
        $this->assertSame(Precommande::STATUT_EN_ATTENTE, $precommande->status);
    }

    public function test_un_ecart_d_un_centime_ne_refuse_pas_la_conversion(): void
    {
        // Le projet a deja un precedent de divergence d'un centime entre
        // round() cote PHP et toFixed(2) cote client : une egalite stricte
        // sur des flottants refuserait des paiements parfaitement valides.
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['code' => '0'],
            ['name' => 'Transaction traitée avec succès', 'is_paid' => true]
        );

        $precommande = $this->precommandeAvecLignes();

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok',
            'transaction' => ['status' => '0', 'reference' => $precommande->refernce, 'amount' => 5499.99],
        ], 200)]);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $precommande->refernce,
            'orderNumber' => 'ORD-CENTIME',
            'amount' => 5500,
            'amountCustomer' => 5500,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000021',
            'provider_reference' => 'PROV-CENTIME',
        ])->assertStatus(201);

        $this->assertSame(1, Commande::query()->count());
    }

    public function test_une_precommande_se_convertit_encore_si_la_transaction_n_a_pas_de_montant(): void
    {
        // Mode ouvert sur le MONTANT : le nom du champ n'a pas ete etabli sur
        // une capture reelle, une hypothese fausse doit degrader vers le
        // comportement d'hier, jamais vers le refus.
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['code' => '0'],
            ['name' => 'Transaction traitée avec succès', 'is_paid' => true]
        );

        $precommande = $this->precommandeAvecLignes();

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok',
            'transaction' => ['status' => '0', 'reference' => $precommande->refernce],
        ], 200)]);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $precommande->refernce,
            'orderNumber' => 'ORD-SANS-MONTANT',
            'amount' => 5500,
            'amountCustomer' => 5500,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000022',
            'provider_reference' => 'PROV-SANS-MONTANT',
        ])->assertStatus(201);

        $this->assertSame(1, Commande::query()->count());
    }

    /**
     * L'asymetrie du montant, epinglee : chemin de production vivant, on
     * observe sans refuser. Un ecart peut avoir des causes legitimes encore
     * inconnues (arrondis, devise, frais operateur).
     */
    public function test_une_commande_ordinaire_au_montant_divergent_est_journalisee_mais_passe(): void
    {
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['code' => '0'],
            ['name' => 'Transaction traitée avec succès', 'is_paid' => true]
        );

        $commande = Commande::query()->create([
            'refernce' => '4247',
            'user_id' => \App\Models\User::factory()->create()->id,
            'status_id' => 5,
            'global_price' => 7000,
        ]);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok',
            'transaction' => ['status' => '0', 'reference' => '4247', 'amount' => 100],
        ], 200)]);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => '4247',
            'orderNumber' => 'ORD-ECART',
            'amount' => 7000,
            'amountCustomer' => 7000,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000023',
            'provider_reference' => 'PROV-ECART',
        ])->assertStatus(201);

        $commande->refresh();

        $this->assertSame(2, (int) $commande->status_id);
        $this->assertNotNull($commande->paied_at);

        // Rien n'est refuse, mais rien n'est tu : sans cette trace, l'ecart
        // serait invisible et l'asymetrie ne serait qu'un renoncement.
        $this->assertStringContainsString('montant verifie different du prix de la commande', $this->journal());
    }
}
