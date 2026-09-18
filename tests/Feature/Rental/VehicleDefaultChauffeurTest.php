<?php

namespace Tests\Feature\Rental;

use App\Models\Chauffeur;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le chauffeur attitre d'un vehicule.
 *
 * Il s'ajoute a l'affectation portee par la reservation, il ne la remplace
 * pas : un vehicule change de conducteur selon les jours, et deux creneaux du
 * meme vehicule peuvent etre confies a deux personnes.
 */
class VehicleDefaultChauffeurTest extends TestCase
{
    use RefreshDatabase;

    private function chauffeur(string $name = 'Jean Kabila'): Chauffeur
    {
        return Chauffeur::query()->create([
            'user_id' => User::factory()->create()->id,
            'full_name' => $name,
            'phone' => '+243810000000',
        ]);
    }

    private function vehicle(array $attributes = []): Vehicle
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'USD'],
            ['title' => 'Dollars', 'is_active' => 1],
        );

        return Vehicle::query()->create(array_merge([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-' . fake()->unique()->numberBetween(1000, 9999),
            'hourly_rate' => 10,
            'currency_id' => $currency->id,
        ], $attributes));
    }

    public function test_un_vehicule_porte_son_conducteur_habituel(): void
    {
        $chauffeur = $this->chauffeur();

        $vehicle = $this->vehicle(['default_chauffeur_id' => $chauffeur->id]);

        $this->assertSame($chauffeur->id, $vehicle->defaultChauffeur->id);
    }

    public function test_un_chauffeur_peut_conduire_plusieurs_vehicules(): void
    {
        $chauffeur = $this->chauffeur();

        $this->vehicle(['default_chauffeur_id' => $chauffeur->id]);
        $this->vehicle(['default_chauffeur_id' => $chauffeur->id]);

        $this->assertCount(2, $chauffeur->refresh()->vehicles);
    }

    public function test_un_vehicule_peut_n_avoir_aucun_chauffeur_attitre(): void
    {
        // Toutes les fiches ne seront pas completes des le premier jour.
        $this->assertNull($this->vehicle()->defaultChauffeur);
    }

    public function test_supprimer_un_chauffeur_ne_supprime_pas_le_vehicule(): void
    {
        $chauffeur = $this->chauffeur();
        $vehicle = $this->vehicle(['default_chauffeur_id' => $chauffeur->id]);

        // `nullOnDelete` : retirer un chauffeur du parc ne doit pas emporter
        // les vehicules qu'il conduisait.
        $chauffeur->forceDelete();

        $vehicle->refresh();
        $this->assertTrue($vehicle->exists);
        $this->assertNull($vehicle->default_chauffeur_id);
    }
}
