<?php

namespace Tests\Feature\Portee;

use App\Filament\Pages\ManageFoodSettings;
use App\Models\User;
use App\Settings\FoodSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Les regles de livraison se reglent depuis l'administration.
 *
 * Le rayon global et le message de refus vivaient dans les reglages sans
 * qu'aucun ecran ne les expose : ils n'etaient modifiables nulle part. Cette
 * page les ouvre, avec l'option de position du client.
 */
class AdminReglagesLivraisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * De vraies lignes, pas des reglages truques.
         *
         * La page d'administration lit les reglages reels au montage, et une
         * autre suite vide la table `settings` : `FoodSettings::fake()` passe
         * seul puis echoue en suite complete. C'est le patron deja suivi par
         * les tests du formulaire de reservation.
         */
        foreach ([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km.',
            'use_client_position' => false,
        ] as $nom => $valeur) {
            DB::table('settings')->updateOrInsert(
                ['group' => 'food', 'name' => $nom],
                ['payload' => json_encode($valeur), 'locked' => false],
            );
        }

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn () => true);
        $this->actingAs($admin);
    }

    public function test_l_option_de_position_s_enregistre(): void
    {
        Livewire::test(ManageFoodSettings::class)
            ->fillForm([
                'max_delivery_radius_km' => 30,
                'out_of_radius_message' => ':restaurant est à :distance km.',
                'use_client_position' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(app(FoodSettings::class)->use_client_position);
    }

    public function test_le_rayon_global_et_le_message_sont_modifiables(): void
    {
        Livewire::test(ManageFoodSettings::class)
            ->fillForm([
                'max_delivery_radius_km' => 18,
                'out_of_radius_message' => 'Trop loin de chez vous.',
                'use_client_position' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reglages = app(FoodSettings::class);

        $this->assertSame(18.0, $reglages->max_delivery_radius_km);
        $this->assertSame('Trop loin de chez vous.', $reglages->out_of_radius_message);
    }

    /** Zero fermerait toute la plateforme sur une faute de frappe. */
    public function test_un_rayon_a_zero_est_refuse(): void
    {
        Livewire::test(ManageFoodSettings::class)
            ->fillForm([
                'max_delivery_radius_km' => 0,
                'out_of_radius_message' => 'Trop loin.',
                'use_client_position' => false,
            ])
            ->call('save')
            ->assertHasFormErrors(['max_delivery_radius_km']);
    }
}
