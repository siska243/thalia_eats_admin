<?php

namespace Tests\Feature\Api;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuoteEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_endpoint_exige_une_authentification(): void
    {
        $this->postJson('/api/quote', [])->assertStatus(401);
    }

    public function test_il_renvoie_le_detail_du_chiffrage(): void
    {
        // ['*'] modelise un client applicatif : createToken() sans arguments
        // accorde cette ability, et c'est ce que portent les jetons du web et du
        // mobile en production. Sans elle, Sanctum::actingAs cree un jeton SANS
        // aucune ability, qui ne modelise aucun client reel.
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $town = Town::factory()->create();
        $currency = Currency::factory()->create();

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 2000, 'service_price' => 500,
        ]);

        $product = Product::factory()->create([
            'currency_id' => $currency->id,
            'price' => 1500,
        ]);

        $response = $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => [
                ['uid' => Cipher::Encrypt($product->id), 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'disponible' => true,
                'sous_total' => 3000,
                'frais_livraison' => 2000,
                'service_price' => 500,
                'total' => 5500,
            ]);

        // Les diagnostics internes ne fuitent pas vers les clients.
        $response->assertJsonMissingPath('warnings');
        $response->assertJsonMissingPath('bracket');
        $response->assertJsonMissingPath('bracket_id');
    }

    public function test_un_panier_multi_restaurants_est_refuse_avec_sa_raison(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        DelivreryPrice::factory()->create(['town_id' => $town->id, 'currency_id' => $currency->id]);

        $a = Product::factory()->create(['currency_id' => $currency->id]);
        $b = Product::factory()->create(['currency_id' => $currency->id]);

        $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => [
                ['uid' => Cipher::Encrypt($a->id), 'quantity' => 1],
                ['uid' => Cipher::Encrypt($b->id), 'quantity' => 1],
            ],
        ])->assertStatus(200)->assertJson([
            'disponible' => false,
            'raison' => 'multi_restaurant',
        ]);
    }

    public function test_le_restaurant_attendu_sert_de_garde_fou(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        DelivreryPrice::factory()->create(['town_id' => $town->id, 'currency_id' => $currency->id]);

        $product = Product::factory()->create(['currency_id' => $currency->id]);
        $autre = Restaurant::factory()->create();

        $this->postJson('/api/quote', [
            'town' => $town->slug,
            'restaurant' => $autre->slug,
            'products' => [['uid' => Cipher::Encrypt($product->id), 'quantity' => 1]],
        ])->assertStatus(200)->assertJson([
            'disponible' => false,
            'raison' => 'restaurant_inattendu',
        ]);
    }

    public function test_un_uid_illisible_renvoie_une_erreur_400(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $town = Town::factory()->create();

        $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => [['uid' => 'pas-un-uid-chiffre', 'quantity' => 1]],
        ])->assertStatus(400);
    }

    public function test_un_produit_inactif_est_introuvable(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $town = Town::factory()->create();
        $product = Product::factory()->inactive()->create();

        $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => [['uid' => Cipher::Encrypt($product->id), 'quantity' => 1]],
        ])->assertStatus(400);
    }

    public function test_une_town_inconnue_renvoie_404(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $product = Product::factory()->create();

        $this->postJson('/api/quote', [
            'town' => 'town-qui-n-existe-pas',
            'products' => [['uid' => Cipher::Encrypt($product->id), 'quantity' => 1]],
        ])->assertStatus(404);
    }

    public function test_une_quantite_absente_est_rejetee_par_la_validation(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $town = Town::factory()->create();
        $product = Product::factory()->create();

        $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => [['uid' => Cipher::Encrypt($product->id)]],
        ])->assertStatus(422);
    }

    public function test_un_panier_de_plus_de_cent_produits_est_rejete_par_la_validation(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $town = Town::factory()->create();
        $product = Product::factory()->create();

        $uid = Cipher::Encrypt($product->id);

        $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => array_fill(0, 101, ['uid' => $uid, 'quantity' => 1]),
        ])->assertStatus(422);
    }

    /**
     * `products` valide comme `array`, mais un objet JSON tel que
     * {"a": {"uid": ..., "quantity": 1}} passe aussi cette validation et
     * produit des clés non séquentielles. resolveLines() lisait autrefois
     * $found->get($ids[$index]) où $index venait de la clé de $products :
     * une clé non entière ("a") plantait avec une ErreurException (500) au
     * lieu d'une réponse d'erreur exploitable par un client agent/MCP.
     */
    public function test_un_products_sous_forme_d_objet_json_ne_provoque_pas_une_erreur_500(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $town = Town::factory()->create();

        // Un produit inactif ne se résout jamais (ModelNotFoundException) :
        // avec une clé non entière ('a'), l'ancien code lisait $ids['a'] —
        // une clé indéfinie — et plantait en 500 AVANT même d'atteindre cette
        // logique de "produit introuvable". Le comportement attendu est
        // identique à celui d'un panier normal (clés séquentielles) : 400.
        $product = Product::factory()->inactive()->create();

        // Un tableau PHP avec une clé non entière ('a') est sérialisé par
        // json_encode() comme un objet JSON, exactement le cas qui plantait.
        $response = $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => [
                'a' => ['uid' => Cipher::Encrypt($product->id), 'quantity' => 1],
            ],
        ]);

        $this->assertLessThan(500, $response->getStatusCode());
        $response->assertStatus(400);
    }
}
