<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\SubCategoryProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'price' => 1000.0,
            'promotionnalPrice' => null,
            'restaurant_id' => Restaurant::factory(),
            'sub_category_product_id' => SubCategoryProduct::factory(),
            'picture' => null,
            'is_active' => true,
            'currency_id' => Currency::factory(),
            'deleted_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
