<?php

namespace Tests\Feature\Portee;

use App\Models\Restaurant;
use App\Models\Town;
use App\Services\ProductSearchService;
use App\Settings\FoodSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le rayon envoye par le client ne fait que RESSERRER celui du reglage.
 *
 * Avant ce lot, `radius` venait entierement du client : il filtrait un
 * affichage sans rien contraindre, et `radius=10000` rendait tout le pays
 * eligible. La borne haute appartient au serveur, comme le prix.
 */
class RecherchePlatsRayonTest extends TestCase
{
    use RefreshDatabase;

    private const GOMBE = [-4.3045, 15.3075];

    private const LIMETE = [-4.3700, 15.3500];

    private const LUBUMBASHI = [-11.6600, 27.4800];

    protected function setUp(): void
    {
        parent::setUp();

        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => 'Trop loin.',
        ], loadMissingValues: false);
    }

    public function test_un_rayon_client_demesure_est_plafonne_par_le_reglage(): void
    {
        $loin = Restaurant::factory()->located(...self::LUBUMBASHI)->create();

        $resultat = app(ProductSearchService::class)->eligibleRestaurants([
            'lat' => self::GOMBE[0],
            'lng' => self::GOMBE[1],
            'radius' => 10000.0,
        ]);

        $this->assertNotContains($loin->id, $resultat['ids']);
    }

    public function test_un_rayon_client_plus_serre_que_le_reglage_est_respecte(): void
    {
        $proche = Restaurant::factory()->located(...self::LIMETE)->create();

        $resultat = app(ProductSearchService::class)->eligibleRestaurants([
            'lat' => self::GOMBE[0],
            'lng' => self::GOMBE[1],
            'radius' => 1.0,
        ]);

        // Gombe-Limete fait environ 9 km : un rayon de 1 km exclut.
        $this->assertNotContains($proche->id, $resultat['ids']);
    }

    public function test_sans_rayon_client_le_reglage_s_applique_quand_meme(): void
    {
        $loin = Restaurant::factory()->located(...self::LUBUMBASHI)->create();
        $proche = Restaurant::factory()->located(...self::LIMETE)->create();

        $resultat = app(ProductSearchService::class)->eligibleRestaurants([
            'lat' => self::GOMBE[0],
            'lng' => self::GOMBE[1],
        ]);

        $this->assertNotContains($loin->id, $resultat['ids']);
        $this->assertContains($proche->id, $resultat['ids']);
    }

    /**
     * Un GPS qui n'a pas fixe ne doit pas vider le catalogue.
     *
     * `eligibleRestaurants()` calcule sa distance lui-meme au lieu de passer
     * par `PorteeLivraison::pour()` : le garde du point zero ne s'y appliquait
     * pas. Et comme le plafond serveur est desormais TOUJOURS actif, meme sans
     * rayon envoye par le client, un (0, 0) excluait tous les restaurants
     * geolocalises — la recherche rendait zero resultat.
     */
    public function test_une_position_a_zero_zero_ne_vide_pas_la_recherche(): void
    {
        $proche = Restaurant::factory()->located(...self::LIMETE)->create();
        $loin = Restaurant::factory()->located(...self::LUBUMBASHI)->create();

        $resultat = app(ProductSearchService::class)->eligibleRestaurants([
            'lat' => 0.0,
            'lng' => 0.0,
        ]);

        // Position inconnue : aucun filtrage, comme avant ce lot.
        $this->assertContains($proche->id, $resultat['ids']);
        $this->assertContains($loin->id, $resultat['ids']);
    }

    /** Un restaurant pose sur le point zero reste trouvable. */
    public function test_un_restaurant_pose_sur_le_point_zero_reste_trouvable(): void
    {
        $town = Town::factory()->create([
            'latitude' => self::LIMETE[0], 'longitude' => self::LIMETE[1],
        ]);
        $restaurant = Restaurant::factory()
            ->located(0.0, 0.0)
            ->create(['town_id' => $town->id]);

        $resultat = app(ProductSearchService::class)->eligibleRestaurants([
            'lat' => self::GOMBE[0],
            'lng' => self::GOMBE[1],
        ]);

        $this->assertContains($restaurant->id, $resultat['ids']);
    }

    public function test_sans_position_aucun_filtrage(): void
    {
        $loin = Restaurant::factory()->located(...self::LUBUMBASHI)->create();

        $resultat = app(ProductSearchService::class)->eligibleRestaurants([]);

        $this->assertContains($loin->id, $resultat['ids']);
    }
}
