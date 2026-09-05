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

    protected function setUp(): void
    {
        parent::setUp();
        Status::factory()->create(['id' => 2]);
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
            'transaction' => ['status' => '0', 'reference' => $precommande->refernce],
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

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $precommande->refernce,
            'orderNumber' => 'ORD-VOLE-2',
            'amount' => 5500,
            'amountCustomer' => 5500,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000011',
            'provider_reference' => 'PROV-VOLE-2',
        ])->assertStatus(400);

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
    }
}
