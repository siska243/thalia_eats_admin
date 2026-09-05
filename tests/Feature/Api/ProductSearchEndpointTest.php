<?php

namespace Tests\Feature\Api;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Les recherches full-text InnoDB ne voient pas les lignes insérées dans la
 * transaction en cours : RefreshDatabase (qui enveloppe chaque test dans une
 * transaction jamais commitée) rend donc les résultats MATCH() invisibles.
 * DatabaseTruncation garde chaque test isolé sans transaction englobante.
 */
class ProductSearchEndpointTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * DatabaseTruncation tronque en setUp(), pas en tearDown() : sans ceci, les
     * lignes committées par le dernier test de cette classe survivraient et
     * seraient visibles par une classe RefreshDatabase exécutée ensuite.
     */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_l_endpoint_exige_une_authentification(): void
    {
        $this->getJson('/api/products/search')->assertStatus(401);
    }

    public function test_il_trouve_un_plat_par_son_titre(): void
    {
        // ['*'] modelise un client applicatif : createToken() sans arguments
        // accorde cette ability, et c'est ce que portent les jetons du web et du
        // mobile en production. Sans elle, Sanctum::actingAs cree un jeton SANS
        // aucune ability, qui ne modelise aucun client reel.
        Sanctum::actingAs(User::factory()->create(), ['*']);

        Product::factory()->create(['title' => 'Poulet moambe', 'description' => 'plat traditionnel']);
        Product::factory()->create(['title' => 'Salade verte', 'description' => 'entree fraiche']);

        $response = $this->getJson('/api/products/search?q=poulet');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Poulet moambe', $response->json('data.0.product.title'));
    }

    public function test_il_trouve_un_plat_sur_un_prefixe(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        Product::factory()->create(['title' => 'Poulet moambe', 'description' => 'plat traditionnel']);

        $response = $this->getJson('/api/products/search?q=poul');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_il_exclut_les_produits_inactifs(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        Product::factory()->inactive()->create(['title' => 'Poulet moambe']);

        $response = $this->getJson('/api/products/search?q=poulet');

        $this->assertCount(0, $response->json('data'));
    }

    public function test_il_exclut_les_produits_des_restaurants_inactifs(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $restaurant = Restaurant::factory()->inactive()->create();
        Product::factory()->create(['title' => 'Poulet moambe', 'restaurant_id' => $restaurant->id]);

        $response = $this->getJson('/api/products/search?q=poulet');

        $this->assertCount(0, $response->json('data'));
    }

    public function test_il_filtre_par_prix_maximum(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        Product::factory()->create(['title' => 'Poulet cher', 'price' => 20000]);
        Product::factory()->create(['title' => 'Poulet abordable', 'price' => 3000]);

        $response = $this->getJson('/api/products/search?q=poulet&price_max=5000');

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Poulet abordable', $response->json('data.0.product.title'));
    }

    public function test_il_filtre_par_devise(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $cdf = Currency::factory()->create();
        $usd = Currency::factory()->usd()->create();

        Product::factory()->create(['title' => 'Poulet en francs', 'currency_id' => $cdf->id]);
        Product::factory()->create(['title' => 'Poulet en dollars', 'currency_id' => $usd->id]);

        $response = $this->getJson('/api/products/search?q=poulet&currency='.$cdf->slug);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Poulet en francs', $response->json('data.0.product.title'));
    }

    public function test_il_calcule_la_distance_quand_les_deux_positions_sont_connues(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $restaurant = Restaurant::factory()->located(-2.508, 28.842)->create();
        Product::factory()->create(['title' => 'Poulet moambe', 'restaurant_id' => $restaurant->id]);

        $response = $this->getJson('/api/products/search?q=poulet&lat=-2.500&lng=28.860');

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.0.distance_km'));
        $this->assertLessThan(5.0, $response->json('data.0.distance_km'));
    }

    public function test_un_restaurant_sans_coordonnees_reste_visible_avec_une_distance_nulle(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $restaurant = Restaurant::factory()->create(['location' => null]);
        Product::factory()->create(['title' => 'Poulet moambe', 'restaurant_id' => $restaurant->id]);

        $response = $this->getJson('/api/products/search?q=poulet&lat=-2.500&lng=28.860');

        $this->assertCount(1, $response->json('data'));
        $this->assertNull($response->json('data.0.distance_km'));
    }

    public function test_une_location_malformee_ne_fait_pas_echouer_la_recherche(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $restaurant = Restaurant::factory()->create(['location' => ['n_importe_quoi' => true]]);
        Product::factory()->create(['title' => 'Poulet moambe', 'restaurant_id' => $restaurant->id]);

        $response = $this->getJson('/api/products/search?q=poulet&lat=-2.500&lng=28.860');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertNull($response->json('data.0.distance_km'));
    }

    public function test_le_filtre_town_garde_les_restaurants_sans_town(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $town = Town::factory()->create();

        $dans_la_town = Restaurant::factory()->create(['town_id' => $town->id]);
        $sans_town = Restaurant::factory()->create(['town_id' => null]);
        $ailleurs = Restaurant::factory()->create(['town_id' => Town::factory()->create()->id]);

        Product::factory()->create(['title' => 'Poulet un', 'restaurant_id' => $dans_la_town->id]);
        Product::factory()->create(['title' => 'Poulet deux', 'restaurant_id' => $sans_town->id]);
        Product::factory()->create(['title' => 'Poulet trois', 'restaurant_id' => $ailleurs->id]);

        $response = $this->getJson('/api/products/search?q=poulet&town='.$town->slug);

        // Le restaurant sans town n'est pas exclu : on ne filtre pas sur une donnée absente.
        $this->assertCount(2, $response->json('data'));
    }

    public function test_les_resultats_sont_pagines(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        Product::factory()->count(7)->create(['title' => 'Poulet moambe']);

        $response = $this->getJson('/api/products/search?q=poulet&per_page=3');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
        $this->assertSame(7, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.per_page'));
    }
}
