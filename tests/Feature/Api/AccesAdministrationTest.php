<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le controle d'acces a l'administration n'a jamais fonctionne.
 *
 * App\Models\User n'implementait pas FilamentUser. Le middleware de Filament
 * retombait donc sur sa seconde branche :
 *
 *     abort_if($user instanceof FilamentUser
 *         ? (! $user->canAccessPanel($panel))
 *         : (config('app.env') !== 'local'), 403);
 *
 * En APP_ENV=local — le poste de developpement — tout utilisateur
 * authentifie entrait dans l'administration, client compris. Partout
 * ailleurs, personne n'entrait. Le canAccessPanel ecrit dans le modele
 * n'etait jamais appele, et type-hintait d'ailleurs une colonne de tableau au
 * lieu du panneau.
 *
 * Ces tests verifient la regle voulue, et non l'environnement.
 */
class AccesAdministrationTest extends TestCase
{
    use DatabaseTruncation;

    private function role(string $nom): Role
    {
        return Role::query()->firstOrCreate(['name' => $nom, 'guard_name' => 'web']);
    }

    public function test_un_utilisateur_sans_role_est_refuse(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertForbidden();
    }

    public function test_un_client_est_refuse(): void
    {
        $client = User::factory()->create();
        $client->assignRole($this->role('client'));

        $this->actingAs($client);

        // C'est le cas qui passait en local : authentifie, donc admis.
        $this->get('/admin')->assertForbidden();
    }

    public function test_le_super_admin_est_admis(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole($this->role('super_admin'));

        $this->actingAs($admin);

        $reponse = $this->get('/admin');

        $this->assertNotSame(403, $reponse->getStatusCode());
    }

    public function test_la_regle_ne_depend_pas_de_l_environnement(): void
    {
        config(['app.env' => 'local']);

        $client = User::factory()->create();
        $client->assignRole($this->role('client'));

        $this->actingAs($client);

        // Avant le correctif, APP_ENV=local suffisait a ouvrir la porte.
        $this->get('/admin')->assertForbidden();
    }

    public function test_le_modele_implemente_le_contrat_de_filament(): void
    {
        // Sans ce contrat, Filament n'appelle jamais canAccessPanel et decide
        // sur le seul environnement.
        $this->assertInstanceOf(
            \Filament\Models\Contracts\FilamentUser::class,
            User::factory()->make()
        );
    }
}
