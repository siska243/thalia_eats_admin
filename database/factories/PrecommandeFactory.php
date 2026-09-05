<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\Precommande;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PrecommandeFactory extends Factory
{
    protected $model = Precommande::class;

    public function definition(): array
    {
        return [
            // Prefixe P- : commandes.refernce est un entier nu et le webhook
            // cherche dessus. Les deux espaces de references ne doivent pas
            // pouvoir se croiser.
            'refernce' => 'P-'.Str::upper(Str::random(10)),
            'user_id' => User::factory(),
            'restaurant_id' => Restaurant::factory(),
            'town_id' => Town::factory(),
            'adresse_delivery' => 'Avenue Test',
            'street' => 'Rue Test',
            'number_street' => '12',
            'reference_adresse' => 'En face du marche',
            'lat' => null,
            'long' => null,
            'recipient_name' => 'Destinataire Test',
            'recipient_phone' => '+243810000000',
            'sous_total' => 3000.0,
            'frais_livraison' => 2000.0,
            'service_price' => 500.0,
            'total' => 5500.0,
            'currency_id' => Currency::factory(),
            'delivrery_price_id' => null,
            'expires_at' => now()->addHours(12),
            'status' => Precommande::STATUT_EN_ATTENTE,
        ];
    }

    public function expiree(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function payee(): static
    {
        return $this->state(fn () => [
            'status' => Precommande::STATUT_PAYEE,
            'paied_at' => now(),
        ]);
    }
}
