<?php

namespace Tests\Unit;

use App\Models\City;
use App\Models\Restaurant;
use App\Models\Town;
use App\Services\PorteeLivraison;
use App\Settings\FoodSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La regle de distance, et elle seule.
 *
 * Gombe (-4.3045, 15.3075) et Limete (-4.3700, 15.3500) sont a environ 9 km.
 * Lubumbashi (-11.6600, 27.4800) est a plus de 1 500 km de Kinshasa.
 */
class PorteeLivraisonTest extends TestCase
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
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
        ], loadMissingValues: false);
    }

    private function portee(): PorteeLivraison
    {
        return app(PorteeLivraison::class);
    }

    /**
     * Efface les reglages ET l'instance truquee du conteneur.
     *
     * Supprimer les lignes ne suffit pas : `FoodSettings::fake()` a deja
     * enregistre un objet charge dans le conteneur, et il continuerait de
     * repondre. C'est l'etat reel d'un serveur dont les migrations n'ont pas
     * tourne qu'on veut reproduire.
     */
    private function sansAucunReglage(): void
    {
        DB::table('settings')->where('group', 'food')->delete();
        app()->forgetInstance(FoodSettings::class);
    }

    public function test_un_restaurant_dans_le_rayon_est_autorise(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $portee = $this->portee()->pour($restaurant, null, ...self::LIMETE);

        $this->assertTrue($portee->autorise);
        $this->assertNull($portee->message);
        $this->assertLessThan(30.0, $portee->distance_km);
    }

    public function test_un_restaurant_hors_rayon_est_refuse_avec_la_distance_dans_le_message(): void
    {
        $restaurant = Restaurant::factory()
            ->located(...self::LUBUMBASHI)
            ->create(['name' => 'Al-Dar']);

        $portee = $this->portee()->pour($restaurant, null, ...self::GOMBE);

        $this->assertFalse($portee->autorise);
        $this->assertGreaterThan(1000.0, $portee->distance_km);
        $this->assertStringContainsString('Al-Dar', $portee->message);
        $this->assertStringContainsString((string) $portee->distance_km, $portee->message);
        $this->assertStringContainsString('30', $portee->message);
    }

    /** Review Focus 1 : la borne est inclusive. */
    public function test_une_distance_egale_au_rayon_est_autorisee(): void
    {
        // Rayon 0 et distance 0 : c'est la comparaison qu'on teste, pas
        // Haversine. Chercher deux points a exactement 30,000 km serait
        // fragile pour la meme assertion.
        $city = City::factory()->create([
            'title' => 'Test', 'is_active' => true, 'max_delivery_radius_km' => 0.0,
        ]);
        $town = Town::factory()->create([
            'city_id' => $city->id, 'latitude' => 1.0, 'longitude' => 1.0,
        ]);
        $restaurant = Restaurant::factory()->create([
            'town_id' => $town->id, 'location' => null,
        ]);

        $portee = $this->portee()->pour($restaurant, $town, 1.0, 1.0);

        $this->assertSame(0.0, $portee->distance_km);
        $this->assertSame(0.0, $portee->rayon_km);
        $this->assertTrue($portee->autorise);
    }

    /** Review Focus 2 : un GPS qui n'a pas fixe renvoie le point zero. */
    public function test_une_position_de_livraison_a_zero_zero_est_ignoree(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $portee = $this->portee()->pour($restaurant, null, 0.0, 0.0);

        $this->assertTrue($portee->autorise);
        $this->assertNull($portee->distance_km);
    }

    public function test_sans_location_on_retombe_sur_le_centroide_de_la_commune(): void
    {
        $town = Town::factory()->create([
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);
        $restaurant = Restaurant::factory()->create([
            'town_id' => $town->id, 'location' => null,
        ]);

        $portee = $this->portee()->pour($restaurant, null, ...self::GOMBE);

        $this->assertFalse($portee->autorise);
        $this->assertGreaterThan(1000.0, $portee->distance_km);
    }

    public function test_la_position_de_livraison_retombe_sur_le_centroide_de_sa_commune(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();
        $livraison = Town::factory()->create([
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);

        $portee = $this->portee()->pour($restaurant, $livraison, null, null);

        $this->assertFalse($portee->autorise);
    }

    /** Review Focus 5 : aucune donnee des deux cotes, aucune exception. */
    public function test_un_restaurant_sans_commune_ni_position_est_autorise(): void
    {
        $restaurant = Restaurant::factory()->create([
            'town_id' => null, 'location' => null,
        ]);

        $portee = $this->portee()->pour($restaurant, null, ...self::GOMBE);

        $this->assertTrue($portee->autorise);
        $this->assertNull($portee->distance_km);
        $this->assertNull($portee->message);
    }

    public function test_le_rayon_de_la_ville_du_restaurant_prime_sur_le_global(): void
    {
        $city = City::factory()->create([
            'title' => 'Lubumbashi', 'is_active' => true, 'max_delivery_radius_km' => 5.0,
        ]);
        $town = Town::factory()->create(['city_id' => $city->id]);
        $restaurant = Restaurant::factory()
            ->located(...self::GOMBE)
            ->create(['town_id' => $town->id]);

        $this->assertSame(5.0, $this->portee()->rayonPour($restaurant->fresh()));

        // 9 km entre Gombe et Limete, rayon 5 : refuse.
        $portee = $this->portee()->pour($restaurant->fresh(), null, ...self::LIMETE);
        $this->assertFalse($portee->autorise);
    }

    /**
     * Les reglages absents ne cassent pas le catalogue.
     *
     * `settings` peut etre vide : migrations pas encore jouees apres un
     * deploiement — c'est deja arrive en production (T76, 500 avec 23
     * migrations en attente). La portee est alors inconnue, et une portee
     * inconnue n'interdit rien. Le contraire ferait tomber la page la plus
     * consultee de l'application pour un reglage manquant.
     */
    public function test_des_reglages_absents_n_empechent_pas_de_commander(): void
    {
        $this->sansAucunReglage();

        $restaurant = Restaurant::factory()->located(...self::LUBUMBASHI)->create();

        $portee = $this->portee()->pour($restaurant, null, ...self::GOMBE);

        $this->assertTrue($portee->autorise);
        $this->assertNull($portee->rayon_km);
        $this->assertNull($portee->message);
    }

    /** Une ville qui a son rayon n'a pas besoin du reglage global. */
    public function test_le_rayon_d_une_ville_s_applique_meme_sans_reglage_global(): void
    {
        $this->sansAucunReglage();

        $city = City::factory()->create([
            'title' => 'Kinshasa', 'is_active' => true, 'max_delivery_radius_km' => 5.0,
        ]);
        $town = Town::factory()->create(['city_id' => $city->id]);
        $restaurant = Restaurant::factory()
            ->located(...self::GOMBE)
            ->create(['town_id' => $town->id]);

        $portee = $this->portee()->pour($restaurant->fresh(), null, ...self::LIMETE);

        $this->assertFalse($portee->autorise);
    }

    public function test_une_ville_sans_surcharge_applique_le_rayon_global(): void
    {
        $city = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);
        $town = Town::factory()->create(['city_id' => $city->id]);
        $restaurant = Restaurant::factory()
            ->located(...self::GOMBE)
            ->create(['town_id' => $town->id]);

        $this->assertSame(30.0, $this->portee()->rayonPour($restaurant->fresh()));
    }
}
