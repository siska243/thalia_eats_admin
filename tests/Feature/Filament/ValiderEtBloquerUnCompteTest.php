<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Valider et bloquer depuis la LISTE des utilisateurs.
 *
 * « bloquer / valider tu le mets sur la liste directement » : on arrive ici
 * en voyant une inscription suspecte, et on doit pouvoir trancher sans ouvrir
 * la fiche.
 */
class ValiderEtBloquerUnCompteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

        // Les droits ne sont pas le sujet : `super_admin` ne donne aucun
        // laissez-passer dans ce projet, et son propre test le couvre.
        Gate::before(fn () => true);

        $this->actingAs($admin);
    }

    public function test_valider_pose_la_date_de_confirmation(): void
    {
        $client = User::factory()->create(['email_verified_at' => null]);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('valider')->table($client));

        $this->assertNotNull($client->fresh()->email_verified_at);
    }

    public function test_bloquer_enregistre_le_motif_et_revoque_les_jetons(): void
    {
        $client = User::factory()->create();
        $client->createToken('api token');

        Livewire::test(ListUsers::class)
            ->callAction(
                TestAction::make('bloquer')->table($client),
                ['raison' => 'Inscriptions automatisées'],
            );

        $client->refresh();

        $this->assertTrue($client->estBloque());
        $this->assertSame('Inscriptions automatisées', $client->blocked_reason);
        $this->assertSame(0, $client->tokens()->count());
    }

    public function test_debloquer_efface_le_blocage_et_son_motif(): void
    {
        $client = User::factory()->create();
        $client->bloquer('Erreur');

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('debloquer')->table($client));

        $client->refresh();

        $this->assertFalse($client->estBloque());
        $this->assertNull($client->blocked_reason);
    }
}
