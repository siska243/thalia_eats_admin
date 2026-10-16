<?php

namespace Tests\Feature;

use App\Filament\Resources\RestaurantResource\Pages\CreateRestaurant;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Les horaires d'ouverture : ce que le formulaire exige, ce que le modele
 * comprend.
 *
 * Les deux se contredisaient. `Restaurant::estOuvert()` tient « aucun horaire
 * saisi » pour ouvert en permanence, et c'est la regle voulue : un
 * restaurateur qui n'a pas rempli ses horaires ne doit pas se retrouver ferme
 * sans le savoir. Le formulaire, lui, ouvrait un creneau vide ET obligatoire :
 * l'etat que le modele traite comme normal etait le seul que l'administration
 * ne pouvait pas enregistrer.
 */
class HorairesRestaurantTest extends TestCase
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

    /** Le formulaire, rempli comme le ferait un gerant qui ne touche pas aux horaires. */
    private function creer(array $champs = []): Testable
    {
        $responsable = User::factory()->create();
        $town = Town::factory()->create();

        return Livewire::test(CreateRestaurant::class)
            ->fillForm(array_merge([
                'user_id' => $responsable->id,
                'name' => 'Al-Dar',
                'adresse' => 'Avenue du Commerce',
                'town_id' => $town->id,
                'reference' => 'ALD1',
                'phone' => '+243900000000',
            ], $champs))
            ->call('create');
    }

    // --- Le formulaire -------------------------------------------------------

    /**
     * Le cas que le formulaire interdisait.
     *
     * On ne passe volontairement PAS la cle `openHours` : c'est le parcours
     * reel, celui du gerant qui ouvre l'ecran et n'ouvre jamais la section des
     * horaires. Un test qui passe `[]` explicitement contourne le defaut au
     * lieu de le prouver.
     */
    public function test_un_restaurant_se_cree_sans_aucun_creneau(): void
    {
        $this->creer()->assertHasNoFormErrors();

        $restaurant = Restaurant::query()->first();

        $this->assertEmpty($restaurant->openHours);
        $this->assertTrue($restaurant->estOuvert());
    }

    /**
     * Un creneau entame reste obligatoire dans son entier.
     *
     * Un creneau a moitie rempli est pire qu'aucun creneau : il ferme le
     * restaurant le jour qu'il nomme, sans dire a quelle heure il rouvre.
     */
    public function test_un_creneau_ajoute_doit_etre_complet(): void
    {
        $this->creer([
            'openHours' => [['day' => 'lundi', 'startAt' => '10:00:00', 'endAt' => null]],
        ])->assertHasFormErrors(['openHours.0.endAt']);
    }

    // --- Le modele : 23:00 - 02:00, c'est le lendemain -----------------------

    /**
     * « 23h - 2h, c'est-a-dire le lendemain. »
     *
     * Un creneau dont la fermeture precede l'ouverture deborde sur le jour
     * suivant. Le modele ne regardait que le creneau du jour COURANT : a 1h du
     * matin un mardi, il cherchait un creneau « mardi », n'en trouvait pas, et
     * fermait un restaurant que le creneau du lundi tenait ouvert jusqu'a 2h.
     */
    public function test_un_creneau_qui_passe_minuit_tient_jusqu_au_lendemain(): void
    {
        $restaurant = Restaurant::factory()->create([
            'openHours' => [['day' => 'lundi', 'startAt' => '23:00:00', 'endAt' => '02:00:00']],
        ]);

        // Lundi 23h30 : ouvert, par le creneau du jour.
        $this->assertTrue($restaurant->estOuvert(Carbon::parse('2026-10-12 23:30:00')));

        // Mardi 1h : ouvert, par le creneau de la VEILLE qui deborde.
        $this->assertTrue($restaurant->estOuvert(Carbon::parse('2026-10-13 01:00:00')));

        // Mardi 3h : ferme, le creneau du lundi s'est acheve a 2h.
        $this->assertFalse($restaurant->estOuvert(Carbon::parse('2026-10-13 03:00:00')));

        // Lundi 10h : ferme, le creneau n'a pas commence.
        $this->assertFalse($restaurant->estOuvert(Carbon::parse('2026-10-12 10:00:00')));
    }

    /** Un creneau ordinaire ne deborde sur rien. */
    public function test_un_creneau_ordinaire_ne_deborde_pas_sur_le_lendemain(): void
    {
        $restaurant = Restaurant::factory()->create([
            'openHours' => [['day' => 'lundi', 'startAt' => '10:00:00', 'endAt' => '22:00:00']],
        ]);

        $this->assertTrue($restaurant->estOuvert(Carbon::parse('2026-10-12 12:00:00')));
        $this->assertFalse($restaurant->estOuvert(Carbon::parse('2026-10-13 01:00:00')));
    }
}
