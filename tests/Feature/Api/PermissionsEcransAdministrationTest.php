<?php

namespace Tests\Feature\Api;

use App\Filament\Pages\Parametres;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Un ecran d'administration livre doit etre visible.
 *
 * `config/filament-shield.php` pose `define_via_gate` a `false` : le role
 * `super_admin` ne contourne rien, il lui faut la permission nommement. Une
 * page dont la permission n'a pas ete generee est donc invisible, meme pour
 * lui — c'est ce qui avait vide le menu Location.
 *
 * Ces tests figent le filet pose par la migration
 * `2026_10_16_100000_permissions_des_ecrans_d_administration`.
 *
 * Ils rejouent cette migration eux-memes plutot que de compter sur celle du
 * demarrage : une autre suite tronque les tables de permissions, et le test
 * passerait seul puis echouerait en suite complete.
 */
class PermissionsEcransAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function rejouerLaMigration(): void
    {
        $fichier = database_path('migrations/2026_10_16_100000_permissions_des_ecrans_d_administration.php');

        $this->assertFileExists($fichier, 'La migration qui genere les permissions a disparu.');

        (require $fichier)->up();
    }

    public function test_la_migration_rend_chaque_ecran_visible_au_super_admin(): void
    {
        $this->rejouerLaMigration();

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->assertTrue(
            Permission::query()->where('name', 'View:'.class_basename(Parametres::class))->exists(),
            "L'ecran des parametres n'a pas de permission : il serait invisible.",
        );

        $this->assertTrue($admin->fresh()->can('View:Parametres'));

        /*
         * Le filet vaut pour TOUS les ecrans, pas seulement celui du jour.
         *
         * Sans cette assertion, la migration pourrait couvrir la page des
         * parametres et laisser le prochain ecran muet — le defaut
         * reviendrait par la porte de derriere.
         */
        $role = Role::findByName('super_admin', 'web');

        $orphelines = Permission::query()
            ->where('guard_name', 'web')
            ->whereNotIn('id', $role->permissions()->pluck('id'))
            ->pluck('name');

        $this->assertEmpty(
            $orphelines,
            'Permissions non rattachees au super_admin : '.$orphelines->implode(', '),
        );
    }

    /**
     * Rejouee, elle ne doit ni doubler ni retirer.
     *
     * `script-run.sh` et le conteneur rejouent les migrations a chaque
     * deploiement : une migration qui dupliquerait ses permissions gonflerait
     * la table a chaque mise en ligne.
     */
    public function test_la_migration_rejouee_ne_double_rien(): void
    {
        $this->rejouerLaMigration();

        $avant = Permission::query()->count();

        $this->rejouerLaMigration();

        $this->assertSame($avant, Permission::query()->count());
        $this->assertGreaterThan(0, $avant);
    }
}
