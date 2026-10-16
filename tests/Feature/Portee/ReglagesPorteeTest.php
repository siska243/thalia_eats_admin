<?php

namespace Tests\Feature\Portee;

use App\Models\City;
use App\Models\Town;
use App\Settings\FoodSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Le rayon de livraison : une valeur globale, qu'une ville peut surcharger.
 */
class ReglagesPorteeTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_reglages_portent_le_rayon_global_et_son_message(): void
    {
        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => 'Trop loin.',
            'use_client_position' => false,
            'city_first' => false,
        ], loadMissingValues: false);

        $settings = app(FoodSettings::class);

        $this->assertSame(30.0, $settings->max_delivery_radius_km);
        $this->assertSame('Trop loin.', $settings->out_of_radius_message);
    }

    public function test_une_ville_peut_surcharger_le_rayon_et_le_relit_en_float(): void
    {
        $city = City::factory()->create([
            'title' => 'Lubumbashi',
            'is_active' => true,
            'max_delivery_radius_km' => 20,
        ]);

        $this->assertSame(20.0, $city->fresh()->max_delivery_radius_km);
    }

    public function test_une_ville_sans_surcharge_vaut_null_et_non_zero(): void
    {
        // Sans cette assertion, le test passerait AVANT la migration : un
        // attribut absent se lit null, et le vert ne prouverait rien.
        $this->assertTrue(Schema::hasColumn('cities', 'max_delivery_radius_km'));

        $city = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);

        // null signifie « cette ville n'a pas d'avis », pas « rayon nul ».
        $this->assertNull($city->fresh()->max_delivery_radius_km);
    }

    public function test_une_ville_expose_ses_communes(): void
    {
        $city = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);
        Town::factory()->create(['city_id' => $city->id]);

        $this->assertCount(1, $city->towns);
    }
}
