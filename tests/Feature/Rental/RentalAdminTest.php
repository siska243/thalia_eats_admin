<?php

namespace Tests\Feature\Rental;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Les ecrans d'administration de la location s'ouvrent.
 *
 * Une Resource qui ne se monte plus ne se voit qu'en l'ouvrant : le schema se
 * construit a l'execution, pas a la compilation.
 */
class RentalAdminTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les reglages sont semes par le test.
     *
     * Ils vivent dans la table `settings`, qu'un autre test de la suite
     * tronque. L'ecran de reglages existe precisement pour les lire : le
     * priver de ses lignes le fait echouer pour une raison qui n'a rien a voir
     * avec lui.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'deposit_percentage' => 10.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
        ] as $name => $value) {
            DB::table('settings')->updateOrInsert(
                ['group' => 'rental', 'name' => $name],
                ['payload' => json_encode($value), 'locked' => false],
            );
        }
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        /*
         * L'autorisation est neutralisee : ce test verifie que les ecrans se
         * construisent, pas qui a le droit de les voir. Dans ce projet,
         * `super_admin` n'ouvre rien par lui-meme — chaque permission
         * s'accorde une par une, et ce comportement merite son propre test.
         */
        Gate::before(fn () => true);

        return $user;
    }

    public static function screens(): array
    {
        return [
            ['/admin/vehicles'],
            ['/admin/vehicles/create'],
            ['/admin/chauffeurs'],
            ['/admin/chauffeurs/create'],
            ['/admin/bookings'],
            ['/admin/bookings/create'],
            ['/admin/manage-rental-settings'],
        ];
    }

    #[DataProvider('screens')]
    public function test_l_ecran_s_ouvre(string $path): void
    {
        $this->actingAs($this->admin())->get($path)->assertSuccessful();
    }
}
