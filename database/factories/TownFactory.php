<?php

namespace Database\Factories;

use App\Models\Town;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TownFactory extends Factory
{
    protected $model = Town::class;

    public function definition(): array
    {
        $title = $this->faker->city();

        return [
            'title' => $title,
            'zip' => null,
            'slug' => Str::slug($title).'-'.Str::random(8),
            'is_active' => true,
        ];
    }
}
