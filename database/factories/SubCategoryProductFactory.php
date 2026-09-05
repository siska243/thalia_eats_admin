<?php

namespace Database\Factories;

use App\Models\CategoryProduct;
use App\Models\SubCategoryProduct;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SubCategoryProductFactory extends Factory
{
    protected $model = SubCategoryProduct::class;

    public function definition(): array
    {
        $title = $this->faker->word();

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(8),
            'picture' => null,
            'is_active' => true,
            // Colonne verifiee sur la base reelle : category_product_id.
            // Le fichier de migration, qui dit « category_id », est perime.
            'category_product_id' => CategoryProduct::factory(),
        ];
    }
}
