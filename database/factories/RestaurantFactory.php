<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RestaurantFactory extends Factory
{
    protected $model = Restaurant::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(8),
            'adresse' => $this->faker->address(),
            'description' => $this->faker->sentence(),
            'reference' => Str::random(6),
            'openHours' => null,
            'is_active' => true,
            'banniere' => null,
            'phone' => '+243900000000',
            'whatsapp' => '+243900000000',
            'town_id' => Town::factory(),
            'location' => null,
            'email' => null,
            'deleted_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function located(float $lat, float $lng): static
    {
        return $this->state(fn () => ['location' => ['lat' => $lat, 'lng' => $lng]]);
    }
}
