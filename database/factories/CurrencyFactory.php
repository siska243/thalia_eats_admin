<?php

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    public function definition(): array
    {
        return [
            'title' => 'Franc congolais',
            'code' => 'CDF',
            'icon' => null,
            'slug' => 'cdf-'.Str::random(8),
            'is_active' => true,
        ];
    }

    public function usd(): static
    {
        return $this->state(fn () => [
            'title' => 'Dollar américain',
            'code' => 'USD',
            'slug' => 'usd-'.Str::random(8),
        ]);
    }
}
