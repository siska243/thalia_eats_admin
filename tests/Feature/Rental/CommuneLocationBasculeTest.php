<?php

namespace Tests\Feature\Rental;

use App\Filament\Resources\TownResource\Pages\ListTowns;
use App\Models\Town;
use App\Models\User;
use App\Settings\RentalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ouvrir une commune a la location, depuis la liste.
 *
 * La bascule n'existait que dans le formulaire de modification : ouvrir les
 * vingt-quatre communes de Kinshasa demandait vingt-quatre allers-retours, et
 * le reglage etait introuvable. Ce qui compte n'est pas la case elle-meme
 * mais le bout de la chaine : cocher ici doit changer ce que l'application
 * lit dans `GET /api/rental/config`.
 */
class CommuneLocationBasculeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * `/api/rental/config` lit les reglages : une autre suite vide la
         * table `settings`, et sans ce faux le test passe seul mais tombe
         * dans la suite complete. `loadMissingValues: false` impose la liste
         * complete.
         */
        RentalSettings::fake([
            'deposit_percentage' => 10.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
            'hours_min' => 1,
            'hours_max' => 23,
            'days_min' => 1,
            'days_max' => 30,
            'lead_time_minutes' => 30,
            'whatsapp_number' => '',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Nous ne desservons pas encore cette commune.',
        ], loadMissingValues: false);

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

        // Les droits ne sont pas le sujet : `super_admin` ne donne aucun
        // laissez-passer dans ce projet, et son propre test le couvre.
        Gate::before(fn () => true);

        $this->admin = $admin;
    }

    public function test_la_bascule_depuis_la_liste_ouvre_la_commune_a_l_api(): void
    {
        $commune = Town::factory()->create([
            'title' => 'Gombe',
            'rental_available' => false,
        ]);

        $this->getJson('/api/rental/config')
            ->assertSuccessful()
            ->assertJsonMissing(['title' => 'Gombe']);

        $this->actingAs($this->admin);

        Livewire::test(ListTowns::class)
            ->call('updateTableColumnState', 'rental_available', (string) $commune->id, true);

        $this->assertTrue((bool) $commune->refresh()->rental_available);

        // La session d'administration est oubliee avant de relire l'API :
        // `/api/rental/config` est publique, et un utilisateur sans jeton
        // applicatif se fait fermer la porte par RefuserAgentSansAbility.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/rental/config')
            ->assertSuccessful()
            ->assertJsonPath('data.towns.0.title', 'Gombe');
    }
}
