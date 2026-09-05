<?php

namespace Tests\Feature\Api;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Status;
use App\Models\StatusPayement;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommandeValideNonRegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $log_path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log_path = storage_path('logs/quotation-test.log');
        @unlink($this->log_path);

        config(['logging.channels.quotation' => [
            'driver' => 'single',
            'path' => $this->log_path,
            'level' => 'debug',
        ]]);

        Http::fake([
            '*' => Http::response([
                'code' => 0,
                'orderNumber' => 'TEST-ORDER-1',
                'message' => 'Transaction initiee',
            ], 200),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->log_path);
        parent::tearDown();
    }

    /**
     * Contexte où le serveur calcule 5500 (3000 de plat + 2000 + 500) :
     * les tests envoient volontairement un total_price different.
     *
     * @return array{0: Town, 1: Currency, 2: Product}
     */
    private function contexte(): array
    {
        Status::factory()->create(['id' => 5]);

        // valide() exige un StatusPayement par defaut (StatusPayementSeeder,
        // deja execute en production via script-run.sh, mais absent de la
        // base de test tant qu'un test ne le cree pas lui-meme).
        StatusPayement::query()->firstOrCreate(
            ['code' => '2'],
            ['name' => 'Paiement en attente', 'is_default' => true]
        );

        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        $restaurant = Restaurant::factory()->create(['town_id' => $town->id]);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 2000, 'service_price' => 500,
        ]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $currency->id,
            'price' => 1500,
        ]);

        return [$town, $currency, $product];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Town $town, Currency $currency, Product $product, float $total_price): array
    {
        return [
            // method "cart" evite la validation de numero de telephone.
            'method' => 'cart',
            'total_price' => $total_price,
            'phone' => '+243810000000',
            'mobile' => '+243810000000',
            'success_url' => 'https://example.test/ok',
            'error_url' => 'https://example.test/ko',
            'cancel_url' => 'https://example.test/cancel',
            'callback_url' => 'https://example.test/callback',
            'webhook_sse_url' => 'https://example.test/sse',
            'pricing' => [
                'frais_livraison' => 2000,
                'service_price' => 500,
                'currency' => ['id' => $currency->id, 'code' => $currency->code],
            ],
            'products' => [
                ['uid' => Cipher::Encrypt($product->id), 'quantity' => 2],
            ],
            'address' => [
                'town' => ['slug' => $town->slug],
                'adresse' => 'Avenue Test',
                'street' => 'Rue Test',
                'number_street' => '12',
                'reference' => 'En face du marche',
            ],
        ];
    }

    public function test_le_client_reste_autorite_sur_le_prix_quand_le_flag_est_a_false(): void
    {
        config(['quotation.authoritative' => false]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        // Le serveur calculerait 5500. Le client annonce 4000.
        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 4000))
            ->assertStatus(201);

        $this->assertDatabaseHas('commandes', ['global_price' => 4000]);
        $this->assertDatabaseHas('payements', ['amount' => 4000, 'amount_customer' => 4000]);
    }

    public function test_flexpay_recoit_le_montant_du_client_quand_le_flag_est_a_false(): void
    {
        config(['quotation.authoritative' => false]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 4000))
            ->assertStatus(201);

        Http::assertSent(fn ($request) => (float) $request['amount'] === 4000.0);
    }

    public function test_l_ecart_est_journalise_sur_le_canal_quotation(): void
    {
        config(['quotation.authoritative' => false]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 4000))
            ->assertStatus(201);

        $this->assertFileExists($this->log_path);

        $contenu = file_get_contents($this->log_path);
        $this->assertStringContainsString('ecart_quotation', $contenu);
    }

    public function test_aucun_ecart_n_est_journalise_quand_les_deux_calculs_concordent(): void
    {
        config(['quotation.authoritative' => false]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        // 1500 x 2 = 3000, + 2000 + 500 = 5500 : exactement ce que le serveur calcule.
        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 5500))
            ->assertStatus(201);

        if (file_exists($this->log_path)) {
            $this->assertStringNotContainsString('ecart_quotation', file_get_contents($this->log_path));
        }

        $this->assertDatabaseHas('commandes', ['global_price' => 5500]);
    }

    public function test_le_serveur_devient_autorite_quand_le_flag_est_a_true(): void
    {
        config(['quotation.authoritative' => true]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 4000))
            ->assertStatus(201);

        $this->assertDatabaseHas('commandes', ['global_price' => 5500]);
        Http::assertSent(fn ($request) => (float) $request['amount'] === 5500.0);
    }

    public function test_un_refus_du_moteur_est_journalise_a_part_et_ne_compte_pas_comme_ecart(): void
    {
        config(['quotation.authoritative' => false]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        // Un second produit d'un AUTRE restaurant : le moteur refuse, le client
        // non — calculePrice.js ne connaît pas cette règle.
        $autre = Product::factory()->create([
            'currency_id' => $currency->id,
            'price' => 1000,
        ]);

        $payload = $this->payload($town, $currency, $product, 4000);
        $payload['products'][] = ['uid' => Cipher::Encrypt($autre->id), 'quantity' => 1];

        $this->postJson('/api/user/commande/valide', $payload)->assertStatus(201);

        $contenu = file_exists($this->log_path) ? file_get_contents($this->log_path) : '';

        $this->assertStringContainsString('refus_quotation', $contenu);
        $this->assertStringNotContainsString('ecart_quotation', $contenu);

        // Le client reste autorité : un refus ne change rien au montant.
        $this->assertDatabaseHas('commandes', ['global_price' => 4000]);
    }

    public function test_une_observation_impossible_ne_casse_pas_la_commande(): void
    {
        config(['quotation.authoritative' => false]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        $payload = $this->payload($town, $currency, $product, 4000);
        $payload['products'][] = ['uid' => 'uid-illisible', 'quantity' => 1];

        // valide() doit continuer a se comporter comme avant, quoi qu'il arrive
        // dans le bloc d'observation.
        $response = $this->postJson('/api/user/commande/valide', $payload);

        $this->assertContains($response->status(), [201, 500]);
        $this->assertDatabaseHas('commandes', ['global_price' => 4000]);
    }
}
