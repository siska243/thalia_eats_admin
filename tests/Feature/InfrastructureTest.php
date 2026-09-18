<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_suite_tourne_sur_la_base_de_test_dediee(): void
    {
        $database = config('database.connections.mysql.database');

        // On assere le SUFFIXE, pas un nom precis : chaque session de travail a
        // sa propre base pour ne pas tronquer les tables d'une autre pendant
        // qu'elle tourne. Epingler « thalia_eats_test » faisait echouer ce test
        // chez quiconque suivait cette consigne.
        $this->assertStringEndsWith('_test', $database);
        $this->assertNotSame('thalia_eats', $database);
    }

    public function test_les_factories_du_catalogue_produisent_un_produit_complet(): void
    {
        $product = Product::factory()->create();

        $this->assertInstanceOf(Restaurant::class, $product->restaurant);
        $this->assertInstanceOf(Currency::class, $product->currency);
        $this->assertNotEmpty($product->slug);
        $this->assertTrue($product->is_active);
    }

    public function test_la_factory_de_tranche_rattache_une_town_et_une_devise(): void
    {
        $bracket = DelivreryPrice::factory()->create();

        $this->assertInstanceOf(Town::class, $bracket->town);
        $this->assertInstanceOf(Currency::class, $bracket->currency);
        $this->assertTrue($bracket->is_active);
    }
}
