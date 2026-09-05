<?php

namespace Tests\Unit\Services;

use App\Services\RestaurantGeo;
use PHPUnit\Framework\TestCase;

class RestaurantGeoTest extends TestCase
{
    public function test_il_lit_les_cles_lat_et_lng(): void
    {
        $this->assertSame(
            ['lat' => -2.5, 'lng' => 28.86],
            RestaurantGeo::coordinates(['lat' => -2.5, 'lng' => 28.86])
        );
    }

    public function test_il_lit_aussi_latitude_et_longitude(): void
    {
        $this->assertSame(
            ['lat' => -2.5, 'lng' => 28.86],
            RestaurantGeo::coordinates(['latitude' => -2.5, 'longitude' => 28.86])
        );
    }

    public function test_il_lit_aussi_la_cle_long(): void
    {
        $this->assertSame(
            ['lat' => -2.5, 'lng' => 28.86],
            RestaurantGeo::coordinates(['lat' => -2.5, 'long' => 28.86])
        );
    }

    public function test_il_accepte_des_coordonnees_en_chaine(): void
    {
        $this->assertSame(
            ['lat' => -2.5, 'lng' => 28.86],
            RestaurantGeo::coordinates(['lat' => '-2.5', 'lng' => '28.86'])
        );
    }

    public function test_il_renvoie_null_sur_une_valeur_absente_ou_malformee(): void
    {
        $this->assertNull(RestaurantGeo::coordinates(null));
        $this->assertNull(RestaurantGeo::coordinates([]));
        $this->assertNull(RestaurantGeo::coordinates(['lat' => -2.5]));
        $this->assertNull(RestaurantGeo::coordinates(['lat' => 'abc', 'lng' => 'def']));
    }

    public function test_il_rejette_des_coordonnees_hors_bornes(): void
    {
        $this->assertNull(RestaurantGeo::coordinates(['lat' => 120, 'lng' => 28.86]));
        $this->assertNull(RestaurantGeo::coordinates(['lat' => -2.5, 'lng' => 999]));
    }

    public function test_la_distance_entre_deux_points_identiques_est_nulle(): void
    {
        $this->assertSame(0.0, RestaurantGeo::distanceKm(-2.5, 28.86, -2.5, 28.86));
    }

    public function test_la_distance_bukavu_goma_est_de_l_ordre_de_200_km(): void
    {
        // Bukavu (-2.508, 28.842) → Goma (-1.658, 29.220)
        $distance = RestaurantGeo::distanceKm(-2.508, 28.842, -1.658, 29.220);

        $this->assertGreaterThan(90.0, $distance);
        $this->assertLessThan(120.0, $distance);
    }
}
