<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\GerantResource;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * L'ecran des gerants.
 *
 * Un gerant est celui qui porte au moins un restaurant : c'est la definition
 * dont depend ce qu'il voit dans l'application. Les deux autres candidates —
 * `users.type_user` et le role Spatie — divergent en base et ne decident de
 * rien.
 */
class EcranGerantsTest extends TestCase
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

    public function test_l_ecran_s_ouvre(): void
    {
        $this->get(GerantResource::getUrl('index'))->assertSuccessful();
    }

    public function test_seuls_les_porteurs_de_restaurant_apparaissent(): void
    {
        $gerant = User::factory()->create(['name' => 'Patron Al-Dar']);
        Restaurant::factory()->create(['user_id' => $gerant->id]);

        $simpleClient = User::factory()->create(['name' => 'Client ordinaire']);

        Livewire::test(GerantResource\Pages\ListGerants::class)
            ->assertCanSeeTableRecords([$gerant])
            ->assertCanNotSeeTableRecords([$simpleClient]);
    }

    /** Le nombre d'etablissements est l'information qui manquait. */
    public function test_le_nombre_d_etablissements_est_affiche(): void
    {
        $gerant = User::factory()->create();

        foreach (['Bonbon', 'Nicolas Perle', 'MOOD'] as $nom) {
            Restaurant::factory()->create(['user_id' => $gerant->id, 'name' => $nom]);
        }

        Livewire::test(GerantResource\Pages\ListGerants::class)
            ->assertTableColumnStateSet('restaurant_count', 3, $gerant);
    }

    /** Lecture seule : la creation d'un gerant passe par Utilisateurs. */
    public function test_l_ecran_ne_permet_pas_de_creer(): void
    {
        $this->assertFalse(GerantResource::canCreate());
    }
}
