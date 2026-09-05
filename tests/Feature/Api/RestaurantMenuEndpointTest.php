<?php

namespace Tests\Feature\Api;

use App\Models\CategoryProduct;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\SubCategoryProduct;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Une sous-categorie (« Grillades », « Boissons ») est partagee entre tous
 * les restaurants ; ses produits, eux, appartiennent chacun a un restaurant.
 *
 * La carte d'un restaurant remontait les plats des autres restaurants qui
 * partageaient la meme sous-categorie : un client pouvait ajouter a son
 * panier un plat qui n'etait pas au menu du restaurant consulte.
 */
class RestaurantMenuEndpointTest extends TestCase
{
    use DatabaseTruncation;

    private function menuOf(Restaurant $restaurant): array
    {
        $response = $this->getJson("/api/categorie-restaurant/{$restaurant->slug}");
        $response->assertStatus(200);

        return collect($response->json('data'))
            ->flatMap(fn (array $category) => $category['sub_category_product'] ?? [])
            ->flatMap(fn (array $sub) => $sub['product'] ?? [])
            ->pluck('title')
            ->all();
    }

    public function test_la_carte_ne_contient_que_les_plats_du_restaurant(): void
    {
        $category = CategoryProduct::factory()->create();
        $shared = SubCategoryProduct::factory()->create([
            'category_product_id' => $category->id,
        ]);

        $bonbon = Restaurant::factory()->create();
        $concurrent = Restaurant::factory()->create();

        Product::factory()->create([
            'title' => 'Poulet de Bonbon',
            'restaurant_id' => $bonbon->id,
            'sub_category_product_id' => $shared->id,
        ]);

        Product::factory()->create([
            'title' => 'Brochette du concurrent',
            'restaurant_id' => $concurrent->id,
            'sub_category_product_id' => $shared->id,
        ]);

        $titles = $this->menuOf($bonbon);

        $this->assertContains('Poulet de Bonbon', $titles);
        $this->assertNotContains('Brochette du concurrent', $titles);
    }

    public function test_la_carte_exclut_les_plats_inactifs(): void
    {
        $category = CategoryProduct::factory()->create();
        $sub = SubCategoryProduct::factory()->create([
            'category_product_id' => $category->id,
        ]);

        $restaurant = Restaurant::factory()->create();

        Product::factory()->create([
            'title' => 'Plat en vente',
            'restaurant_id' => $restaurant->id,
            'sub_category_product_id' => $sub->id,
        ]);

        Product::factory()->inactive()->create([
            'title' => 'Plat retire de la carte',
            'restaurant_id' => $restaurant->id,
            'sub_category_product_id' => $sub->id,
        ]);

        $titles = $this->menuOf($restaurant);

        $this->assertContains('Plat en vente', $titles);
        $this->assertNotContains('Plat retire de la carte', $titles);
    }

    public function test_le_menu_d_une_sous_categorie_est_aussi_restreint(): void
    {
        $category = CategoryProduct::factory()->create();
        $shared = SubCategoryProduct::factory()->create([
            'category_product_id' => $category->id,
        ]);

        $restaurant = Restaurant::factory()->create();
        $concurrent = Restaurant::factory()->create();

        Product::factory()->create([
            'title' => 'Poulet maison',
            'restaurant_id' => $restaurant->id,
            'sub_category_product_id' => $shared->id,
        ]);

        Product::factory()->create([
            'title' => 'Poulet du concurrent',
            'restaurant_id' => $concurrent->id,
            'sub_category_product_id' => $shared->id,
        ]);

        $response = $this->getJson("/api/menu/{$restaurant->slug}/{$shared->slug}");
        $response->assertStatus(200);

        $titles = collect($response->json('data'))
            ->flatMap(fn (array $sub) => $sub['product'] ?? [])
            ->pluck('title')
            ->all();

        $this->assertContains('Poulet maison', $titles);
        $this->assertNotContains('Poulet du concurrent', $titles);
    }
}
