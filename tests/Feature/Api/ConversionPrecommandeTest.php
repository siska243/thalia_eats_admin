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
            'code' => 0, 'message' => 'ok', 'transaction' => ['status' => '0'],
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
}
