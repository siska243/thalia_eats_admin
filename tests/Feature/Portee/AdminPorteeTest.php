<?php

namespace Tests\Feature\Portee;

use App\Filament\Resources\CityResource\Pages\CreateCity;
use App\Filament\Resources\RestaurantResource\Pages\CreateRestaurant;
use App\Filament\Resources\TownResource\Pages\CreateTown;
use App\Models\City;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * L'administration doit pouvoir saisir ce dont la regle a besoin.
 *
 * Une limite de distance sans ecran pour saisir les coordonnees ne limite
 * rien : tout restaurant et toute commune crees apres la mise en production
 * resteraient sans position, donc hors de portee de la regle. Ces trois
 * formulaires sont la condition pour que le lot serve a quelque chose.
 */
class AdminPorteeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn () => true);
        $this->actingAs($admin);
    }

    // --- La ville porte son rayon -------------------------------------------

    public function test_un_rayon_de_ville_saisi_est_enregistre(): void
    {
        Livewire::test(CreateCity::class)
            ->fillForm([
                'title' => 'Lubumbashi',
                'is_active' => true,
                'max_delivery_radius_km' => 20,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(20.0, City::query()->first()->max_delivery_radius_km);
    }

    public function test_une_ville_sans_rayon_reste_a_null(): void
    {
        Livewire::test(CreateCity::class)
            ->fillForm(['title' => 'Kinshasa', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        // null = « cette ville n'a pas d'avis, applique le rayon global ».
        $this->assertNull(City::query()->first()->max_delivery_radius_km);
    }

    /** Review Focus 4 : zero fermerait la ville par une fausse manipulation. */
    public function test_un_rayon_a_zero_est_refuse_par_le_formulaire(): void
    {
        Livewire::test(CreateCity::class)
            ->fillForm([
                'title' => 'Kinshasa',
                'is_active' => true,
                'max_delivery_radius_km' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['max_delivery_radius_km']);
    }

    // --- La commune porte son centroide -------------------------------------

    public function test_les_coordonnees_d_une_commune_sont_saisissables(): void
    {
        $city = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);

        Livewire::test(CreateTown::class)
            ->fillForm([
                'title' => 'Gombe',
                'city_id' => $city->id,
                'latitude' => -4.3045,
                'longitude' => 15.3075,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $town = Town::query()->first();

        $this->assertSame('-4.304500', $town->latitude);
        $this->assertSame('15.307500', $town->longitude);
    }

    public function test_une_latitude_hors_bornes_est_refusee(): void
    {
        $city = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);

        Livewire::test(CreateTown::class)
            ->fillForm([
                'title' => 'Nulle part',
                'city_id' => $city->id,
                'latitude' => 120,
                'longitude' => 15.3075,
            ])
            ->call('create')
            ->assertHasFormErrors(['latitude']);
    }

    // --- Le restaurant porte sa position ------------------------------------

    public function test_la_position_d_un_restaurant_est_saisissable(): void
    {
        $responsable = User::factory()->create();
        $town = Town::factory()->create();

        Livewire::test(CreateRestaurant::class)
            ->fillForm([
                'user_id' => $responsable->id,
                'name' => 'Al-Dar',
                'adresse' => 'Avenue du Commerce',
                'town_id' => $town->id,
                'reference' => 'ALD1',
                'phone' => '+243900000000',
                // Le formulaire ouvre une ligne d'horaires vide et obligatoire ;
                // un restaurant sans horaires est pourtant considere ouvert.
                'openHours' => [],
                'location.lat' => -4.3045,
                'location.lng' => 15.3075,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $restaurant = Restaurant::query()->first();

        $this->assertEqualsWithDelta(-4.3045, (float) $restaurant->location['lat'], 0.00001);
        $this->assertEqualsWithDelta(15.3075, (float) $restaurant->location['lng'], 0.00001);
    }

    /**
     * La position reste facultative.
     *
     * La rendre obligatoire bloquerait la creation d'un restaurant dont on
     * n'a pas encore releve les coordonnees, et le repli sur le centroide de
     * la commune existe precisement pour ce cas.
     */
    public function test_un_restaurant_sans_position_reste_creable(): void
    {
        $responsable = User::factory()->create();
        $town = Town::factory()->create();

        Livewire::test(CreateRestaurant::class)
            ->fillForm([
                'user_id' => $responsable->id,
                'name' => 'Sans GPS',
                'adresse' => 'Avenue du Commerce',
                'town_id' => $town->id,
                'reference' => 'SGP1',
                'phone' => '+243900000000',
                'openHours' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Restaurant::query()->count());
    }
}
