<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Chaque écran du back-office s'ouvre.
 *
 * La suite existante teste l'API ; le panneau Filament n'était couvert par
 * rien. Or c'est lui qui a traversé deux versions majeures d'un coup — de la
 * 3 à la 5 — et 110 fichiers y ont été réécrits par l'outil de migration.
 * Une Resource qui ne se monte plus ne se voit qu'en l'ouvrant.
 *
 * Le test monte la page de liste de chaque Resource. Il ne vérifie pas le
 * contenu : il vérifie que le schéma se construit, que les colonnes existent
 * et que rien n'explose au rendu — c'est exactement ce qu'une migration
 * d'API casse.
 */
class PanneauAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $role = Role::findOrCreate('super_admin', 'web');

        $utilisateur = User::factory()->create();
        $utilisateur->assignRole($role);

        /*
         * L'autorisation est neutralisee : ce test verifie que les ecrans se
         * construisent apres la migration Filament, pas qui a le droit de les
         * voir. Dans ce projet, `super_admin` ne donne aucun laissez-passer —
         * chaque permission doit etre accordee une par une — et ce comportement
         * merite son propre test plutot que d'etre melange a celui-ci.
         */
        Gate::before(fn () => true);

        return $utilisateur;
    }

    public static function ressources(): array
    {
        $chemins = [
            'category-products', 'cities', 'commande-products', 'commandes',
            'configuration-payements', 'currencies', 'delivrery-drivers',
            'delivrery-prices', 'motos', 'paiment-methods', 'products',
            'restaurants', 'status-payements', 'statuses',
            'sub-category-products', 'towns', 'users',
        ];

        return array_map(fn (string $c) => [$c], $chemins);
    }

    #[DataProvider('ressources')]
    public function test_l_ecran_s_ouvre(string $chemin): void
    {
        $this->actingAs($this->superAdmin())
            ->get("/admin/{$chemin}")
            ->assertSuccessful();
    }

    public function test_le_tableau_de_bord_s_ouvre(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/admin')
            ->assertSuccessful();
    }
}
