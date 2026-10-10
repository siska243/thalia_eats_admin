<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
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

/**
 * « Oui, le serveur fait foi. » Ce fichier garde le sens de cette phrase :
 * le montant facture vient du moteur de quotation, et un client qui annonce
 * autre chose n'est pas cru.
 *
 * Le danger n'est pas la bascule : c'est le repli. Chaque cas ou la cotation
 * n'est pas disponible a son test, parce qu'un repli non teste est une porte
 * par laquelle le prix du client revient.
 */
class PrixServeurFaitFoiTest extends TestCase
{
    use RefreshDatabase;

    private string $log_path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log_path = storage_path('logs/quotation-prix-test.log');
        @unlink($this->log_path);

        config(['logging.channels.quotation' => [
            'driver' => 'single',
            'path' => $this->log_path,
            'level' => 'debug',
        ]]);

        // Explicite, et non herite de `.env.testing` : ce fichier est ignore
        // par git, une suite qui en depend ne dit pas la meme chose ici et
        // sur la machine d'a cote.
        config(['quotation.authoritative' => true]);

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

    private function journal(): string
    {
        return file_exists($this->log_path) ? file_get_contents($this->log_path) : '';
    }

    /**
     * Serveur : 1500 x 2 = 3000, + 2000 de livraison + 500 de service = 5500.
     *
     * @return array{0: Town, 1: Currency, 2: Product}
     */
    private function contexte(bool $avec_tarifs = true): array
    {
        Status::factory()->create(['id' => 5]);

        StatusPayement::query()->firstOrCreate(
            ['code' => '2'],
            ['name' => 'Paiement en attente', 'is_default' => true]
        );

        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        $restaurant = Restaurant::factory()->create(['town_id' => $town->id]);

        if ($avec_tarifs) {
            DelivreryPrice::factory()->create([
                'town_id' => $town->id, 'currency_id' => $currency->id,
                'interval_pricing' => 0, 'interval_max_price' => 100000,
                'frais' => 2000, 'service_price' => 500,
            ]);
        }

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
            'method' => 'cart',
            'total_price' => $total_price,
            'phone' => '+243810000000',
            'mobile' => '+243810000000',
            'success_url' => 'https://example.test/ok',
            'error_url' => 'https://example.test/ko',
            'cancel_url' => 'https://example.test/cancel',
            'pricing' => [
                'frais_livraison' => 0,
                'service_price' => 0,
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

    public function test_le_serveur_fait_foi_sans_qu_on_touche_au_env(): void
    {
        // Le proprietaire n'a pas a editer le `.env` de production pour que la
        // bascule prenne effet : la valeur par defaut suffit.
        $this->assertTrue($this->configSousEnv(null)['authoritative']);
    }

    public function test_le_env_garde_le_bouton_d_arret_d_urgence(): void
    {
        // Et il doit continuer de pouvoir tout arreter sans redeploiement.
        $this->assertFalse($this->configSousEnv('false')['authoritative']);
        $this->assertTrue($this->configSousEnv('true')['authoritative']);
    }

    /**
     * Relit config/quotation.php avec la variable d'environnement posee a la
     * valeur voulue, ou absente.
     *
     * @return array<string, mixed>
     */
    private function configSousEnv(?string $valeur): array
    {
        $avant = getenv('QUOTATION_AUTHORITATIVE');

        putenv($valeur === null ? 'QUOTATION_AUTHORITATIVE' : "QUOTATION_AUTHORITATIVE={$valeur}");
        unset($_ENV['QUOTATION_AUTHORITATIVE'], $_SERVER['QUOTATION_AUTHORITATIVE']);

        if ($valeur !== null) {
            $_ENV['QUOTATION_AUTHORITATIVE'] = $valeur;
        }

        try {
            return require base_path('config/quotation.php');
        } finally {
            putenv($avant === false ? 'QUOTATION_AUTHORITATIVE' : "QUOTATION_AUTHORITATIVE={$avant}");

            if ($avant !== false) {
                $_ENV['QUOTATION_AUTHORITATIVE'] = $avant;
            }
        }
    }

    public function test_un_total_mensonger_du_client_est_ignore(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        // Le client annonce 4000 et zero de frais. Le serveur calcule 5500.
        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 4000))
            ->assertStatus(201);

        $this->assertDatabaseHas('commandes', [
            'global_price' => 5500,
            'price_delivery' => 2000,
            'price_service' => 500,
        ]);
        $this->assertDatabaseHas('payements', ['amount' => 5500, 'amount_customer' => 5500]);
        Http::assertSent(fn ($request) => (float) $request['amount'] === 5500.0);
    }

    public function test_la_devise_facturee_est_celle_des_produits_pas_celle_annoncee(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $autre_devise = Currency::factory()->create();

        $payload = $this->payload($town, $currency, $product, 4000);
        $payload['pricing']['currency'] = ['id' => $autre_devise->id, 'code' => $autre_devise->code];

        $this->postJson('/api/user/commande/valide', $payload)->assertStatus(201);

        Http::assertSent(fn ($request) => $request['currency'] === $currency->code);
        $this->assertDatabaseHas('commande_products', ['currency_id' => $currency->id]);
    }

    public function test_un_produit_introuvable_ne_fait_pas_revenir_le_prix_du_client(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $payload = $this->payload($town, $currency, $product, 4000);
        // Un uid parfaitement lisible, dont le produit n'existe plus : c'est
        // la forme utile de l'attaque, pas la chaine illisible.
        $payload['products'][] = ['uid' => Cipher::Encrypt(999999), 'quantity' => 1];

        $this->postJson('/api/user/commande/valide', $payload)->assertStatus(400);

        $this->assertDatabaseCount('commandes', 0);
        Http::assertNothingSent();
    }

    public function test_une_quantite_invalide_est_refusee_et_non_facturee_au_prix_du_client(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $payload = $this->payload($town, $currency, $product, 4000);
        $payload['products'] = [['uid' => Cipher::Encrypt($product->id), 'quantity' => 0]];

        $this->postJson('/api/user/commande/valide', $payload)->assertStatus(400);

        $this->assertDatabaseCount('commandes', 0);
        Http::assertNothingSent();
    }

    public function test_un_panier_vide_est_refuse(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $payload = $this->payload($town, $currency, $product, 4000);
        $payload['products'] = [];

        $this->postJson('/api/user/commande/valide', $payload)->assertStatus(400);

        $this->assertDatabaseCount('commandes', 0);
    }

    public function test_un_panier_multi_restaurant_garde_le_repli_client_et_alerte(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        // Trois commandes livrees en production sont dans ce cas : refuser
        // casserait un parcours reel. Le repli reste, mais il crie.
        $autre = Product::factory()->create(['currency_id' => $currency->id, 'price' => 1000]);

        $payload = $this->payload($town, $currency, $product, 4000);
        $payload['products'][] = ['uid' => Cipher::Encrypt($autre->id), 'quantity' => 1];

        $this->postJson('/api/user/commande/valide', $payload)->assertStatus(201);

        $this->assertDatabaseHas('commandes', ['global_price' => 4000]);
        $this->assertStringContainsString('repli_prix_client', $this->journal());
    }

    public function test_un_panier_a_devises_melangees_garde_le_repli_client_et_alerte(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $autre = Product::factory()->create([
            'restaurant_id' => $product->restaurant_id,
            'currency_id' => Currency::factory()->create()->id,
            'price' => 1000,
        ]);

        $payload = $this->payload($town, $currency, $product, 4000);
        $payload['products'][] = ['uid' => Cipher::Encrypt($autre->id), 'quantity' => 1];

        $this->postJson('/api/user/commande/valide', $payload)->assertStatus(201);

        $this->assertDatabaseHas('commandes', ['global_price' => 4000]);
        $this->assertStringContainsString('repli_prix_client', $this->journal());
    }

    public function test_une_commune_sans_grille_livre_gratuitement_et_le_dit(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte(avec_tarifs: false);

        // Treize communes de Kinshasa sont dans ce cas. Le client voit « 0 de
        // frais » sur son ecran : lui en facturer serait lui prendre de
        // l'argent qu'il n'a pas accepte de donner.
        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 3000))
            ->assertStatus(201);

        $this->assertDatabaseHas('commandes', [
            'global_price' => 3000,
            'price_delivery' => 0,
            'price_service' => 0,
        ]);
        $this->assertStringContainsString('aucun_tarif_actif', $this->journal());
    }

    public function test_le_drapeau_a_false_rend_exactement_l_ancien_comportement(): void
    {
        config(['quotation.authoritative' => false]);
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 4000))
            ->assertStatus(201);

        $this->assertDatabaseHas('commandes', ['global_price' => 4000]);
    }

    public function test_global_price_porte_le_total_complet_des_la_creation(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        [$town, $currency, $product] = $this->contexte();

        Status::factory()->create(['id' => 1]);

        $commande = Commande::query()->create([
            'user_id' => $user->id,
            'refernce' => 2000,
            'status_id' => 1,
            'town_id' => $town->id,
            'price_delivery' => 2000,
            'price_service' => 500,
        ]);

        $this->postJson('/api/user/commande/add-product', [
            'products' => [
                ['product_id' => Cipher::Encrypt($product->id), 'quantity' => 2, 'pricing' => $currency->id],
            ],
        ])->assertStatus(200);

        // 1500 x 2 + 2000 + 500 : le montant affiche est celui qu'on paiera.
        $this->assertSame(5500.0, (float) $commande->fresh()->global_price);
    }

    public function test_la_ressource_commande_expose_le_detail_sans_perdre_de_cle(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        [$town, $currency, $product] = $this->contexte();

        Status::factory()->create(['id' => 1]);

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 4000))
            ->assertStatus(201);

        $reponse = $this->getJson('/api/user/commande/current')->assertStatus(200);

        $data = $reponse->json('data') ?? $reponse->json();

        foreach (['uid', 'global_price', 'price_delivery', 'price_service', 'sous_total', 'currency', 'reference'] as $cle) {
            $this->assertArrayHasKey($cle, $data, "la cle {$cle} doit rester exposee");
        }

        $this->assertSame(3000.0, (float) $data['sous_total']);
        $this->assertSame(5500.0, (float) $data['global_price']);
    }
}
