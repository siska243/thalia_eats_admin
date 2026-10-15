<?php

namespace Tests\Feature\Portee;

use App\Models\Restaurant;
use App\Models\Town;
use App\Settings\FoodSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le catalogue ne cache rien.
 *
 * Le proprietaire : « il peut voir Hunga Busta de Brazzaville flou, il peut
 * pas acceder ». Un restaurant hors rayon reste donc dans la reponse, avec de
 * quoi le griser et expliquer pourquoi.
 */
class CataloguePorteeTest extends TestCase
{
    use RefreshDatabase;

    private const GOMBE = [-4.3045, 15.3075];

    private const BRAZZAVILLE = [-4.2634, 15.2429];

    private const LUBUMBASHI = [-11.6600, 27.4800];

    protected function setUp(): void
    {
        parent::setUp();

        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
            'use_client_position' => false,
        ], loadMissingValues: false);
    }

    public function test_un_restaurant_hors_rayon_reste_dans_la_liste_marque_non_livrable(): void
    {
        Restaurant::factory()->located(...self::LUBUMBASHI)->create(['name' => 'Al-Dar']);

        $reponse = $this->getJson('/api/list-restaurant?lat='.self::GOMBE[0].'&lng='.self::GOMBE[1]);

        $reponse->assertOk();
        $reponse->assertJsonCount(1, 'data');
        $reponse->assertJsonPath('data.0.livrable', false);
        $this->assertGreaterThan(1000.0, $reponse->json('data.0.distance_km'));
        $this->assertStringContainsString('Al-Dar', $reponse->json('data.0.portee_message'));
    }

    public function test_un_restaurant_dans_le_rayon_est_livrable(): void
    {
        Restaurant::factory()->located(...self::GOMBE)->create();

        $reponse = $this->getJson('/api/list-restaurant?lat='.self::BRAZZAVILLE[0].'&lng='.self::BRAZZAVILLE[1]);

        $reponse->assertJsonPath('data.0.livrable', true);
        $reponse->assertJsonPath('data.0.portee_message', null);
    }

    public function test_sans_position_la_reponse_est_celle_d_avant(): void
    {
        Restaurant::factory()->located(...self::LUBUMBASHI)->create();

        $reponse = $this->getJson('/api/list-restaurant');

        $reponse->assertOk();
        // Aucun controle possible : tout est livrable, comme avant ce lot.
        $reponse->assertJsonPath('data.0.livrable', true);
        $reponse->assertJsonPath('data.0.distance_km', null);
    }

    public function test_la_commune_de_livraison_suffit_quand_la_position_manque(): void
    {
        $livraison = Town::factory()->create([
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);
        Restaurant::factory()->located(...self::GOMBE)->create();

        $reponse = $this->getJson('/api/list-restaurant?town='.$livraison->slug);

        $reponse->assertJsonPath('data.0.livrable', false);
    }
}
