<?php

namespace Tests\Feature\Portee;

use App\Models\Product;
use App\Models\Restaurant;
use App\Settings\FoodSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chercher un restaurant par le nom d'un plat.
 *
 * Le client, le 17 octobre : « au niveau de la liste de restaurant la
 * recherche par nom du plat (produit) ne fonctionne pas ». Elle ne regardait
 * que le nom et la description du restaurant.
 *
 * Le mobile delegue sa recherche a cet endpoint ; le web la refaisait de son
 * cote sur le nom et l'adresse. La regle vit desormais ici, une seule fois.
 */
class RechercheRestaurantParPlatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => 'Trop loin.',
            'use_client_position' => false,
            'city_first' => false,
            'stock_actif' => true,
            'montant_minimum_commande' => 0.0,
        ], loadMissingValues: false);
    }

    public function test_le_nom_d_un_plat_remonte_son_restaurant(): void
    {
        $chezEva = Restaurant::factory()->create(['name' => 'Chez Eva']);
        Product::factory()->create(['restaurant_id' => $chezEva->id, 'title' => 'Poulet braise']);

        Restaurant::factory()->create(['name' => 'Le Bistrot']);

        $reponse = $this->getJson('/api/list-restaurant?search=poulet');

        $reponse->assertOk();
        $reponse->assertJsonCount(1, 'data');
        $reponse->assertJsonPath('data.0.name', 'Chez Eva');
    }

    public function test_la_recherche_par_nom_de_restaurant_marche_toujours(): void
    {
        Restaurant::factory()->create(['name' => 'Chez Eva']);
        Restaurant::factory()->create(['name' => 'Le Bistrot']);

        $reponse = $this->getJson('/api/list-restaurant?search=bistrot');

        $reponse->assertJsonCount(1, 'data');
        $reponse->assertJsonPath('data.0.name', 'Le Bistrot');
    }

    public function test_un_plat_eteint_ne_remonte_pas_son_restaurant(): void
    {
        $chezEva = Restaurant::factory()->create(['name' => 'Chez Eva']);
        Product::factory()->inactive()->create([
            'restaurant_id' => $chezEva->id,
            'title' => 'Poulet braise',
        ]);

        $this->getJson('/api/list-restaurant?search=poulet')->assertJsonCount(0, 'data');
    }

    /**
     * Le defaut d'origine : `where(is_active)->where(name)->orWhere(description)`
     * se lit `is_active AND name OR description`. Un restaurant eteint dont la
     * description contenait le terme ressortait donc dans le catalogue public.
     */
    public function test_un_restaurant_eteint_ne_ressort_jamais(): void
    {
        Restaurant::factory()->create([
            'name' => 'Ferme',
            'description' => 'Le meilleur poulet de Kinshasa',
            'is_active' => false,
        ]);

        $this->getJson('/api/list-restaurant?search=poulet')->assertJsonCount(0, 'data');
    }
}
