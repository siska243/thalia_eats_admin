<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Town;
use Illuminate\Database\Eloquent\Factories\Factory;

class DelivreryPriceFactory extends Factory
{
    protected $model = DelivreryPrice::class;

    public function definition(): array
    {
        return [
            'town_id' => Town::factory(),
            'interval_pricing' => 0,
            'interval_max_price' => 100000.0,
            'frais' => 2000.0,
            'service_price' => 500.0,
            'currency_id' => Currency::factory(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
