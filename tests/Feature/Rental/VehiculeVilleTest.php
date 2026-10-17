<?php

namespace Tests\Feature\Rental;

use App\Filament\Resources\VehicleResource\Pages\EditVehicle;
use App\Filament\Resources\VehicleResource\Pages\ListVehicles;
use App\Models\City;
use App\Models\Currency;
use App\Models\Town;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\RentalSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Un vehicule appartient a une ville.
 *
 * Le proprietaire, le 15 octobre : « il y a pas des limites des kilometres
 * mais il y'a des limites par rapport a la ville (province) ». Et : « ils
 * peuvent voir les vehicules disponibles qui se trouvent dans tous les pays
 * mais ils peuvent commander que pour la course dans le pays d'origine de la
 * voiture ».
 *
 * Le catalogue reste donc MONDIAL, et c'est la reservation qui refuse. Un
 * vehicule sans ville — tout le parc existant — reste reservable partout :
 * aucune regression le jour de la migration.
 */
class VehiculeVilleTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * `loadMissingValues: false` est indispensable : une autre suite vide
         * la table `settings`, et sans ce drapeau le test passe seul mais
         * echoue dans la suite complete.
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
            'whatsapp_number' => '+243900000000',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Nous ne desservons pas encore cette commune.',
        ], loadMissingValues: false);

        $this->currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->client = User::factory()->create();
        Sanctum::actingAs($this->client, ['*']);
    }

    private function vehicule(?City $ville = null): Vehicle
    {
        return Vehicle::query()->create([
            'brand' => 'Toyota',
            'model' => 'Land Cruiser',
            'plate_number' => 'KIN-'.uniqid(),
            'hourly_rate' => 10,
            'currency_id' => $this->currency->id,
            'city_id' => $ville?->id,
        ]);
    }

    /**
     * `$jours` decale le creneau.
     *
     * Deux reservations du MEME vehicule sur le MEME creneau se refusent pour
     * conflit d'agenda, et c'est ce refus-la qui repondrait au lieu de la
     * regle qu'on veut prouver. Le client change aussi : une reservation non
     * reglee bloque la suivante.
     */
    private function reserve(Vehicle $vehicle, Town $depart, int $jours = 2): TestResponse
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        return $this->postJson('/api/rental/bookings', [
            'vehicle' => Cipher::Encrypt($vehicle->id),
            'starts_at' => Carbon::parse("+{$jours} days 10:00")->toDateTimeString(),
            'ends_at' => Carbon::parse("+{$jours} days 15:00")->toDateTimeString(),
            'pickup_location' => 'Gombe',
            'pickup_town_id' => $depart->id,
        ]);
    }

    /** Le cas de non-regression : tout le parc existant n'a pas de ville. */
    public function test_un_vehicule_sans_ville_se_reserve_partout(): void
    {
        $ville = City::factory()->create(['title' => 'Kinshasa']);
        $commune = Town::factory()->create(['city_id' => $ville->id, 'rental_available' => true]);

        $this->reserve($this->vehicule(), $commune)->assertSuccessful();
    }

    public function test_un_vehicule_se_reserve_dans_sa_ville(): void
    {
        $ville = City::factory()->create(['title' => 'Kinshasa']);
        $commune = Town::factory()->create(['city_id' => $ville->id, 'rental_available' => true]);

        $this->reserve($this->vehicule($ville), $commune)->assertSuccessful();
    }

    /**
     * Le refus nomme la ville du vehicule, et porte son propre code.
     *
     * `hors_ville` est distinct de `hors_zone` : « cette commune n'est pas
     * desservie » envoie le client vers WhatsApp, « ce vehicule n'est pas
     * d'ici » l'envoie vers un autre vehicule. Deux suites differentes.
     */
    public function test_un_vehicule_d_une_autre_ville_est_refuse(): void
    {
        $kinshasa = City::factory()->create(['title' => 'Kinshasa']);
        $lubumbashi = City::factory()->create(['title' => 'Lubumbashi']);
        $commune = Town::factory()->create(['city_id' => $kinshasa->id, 'rental_available' => true]);

        $this->reserve($this->vehicule($lubumbashi), $commune)
            ->assertStatus(400)
            ->assertJsonPath('error', 'hors_ville')
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Lubumbashi'));
    }

    /**
     * La commune fermee prime.
     *
     * Une commune peut etre fermee meme dans la bonne ville, et c'est ce
     * refus-la qu'il faut montrer : il envoie vers WhatsApp.
     */
    public function test_une_commune_fermee_prime_sur_la_ville(): void
    {
        $ville = City::factory()->create(['title' => 'Kinshasa']);
        $commune = Town::factory()->create(['city_id' => $ville->id, 'rental_available' => false]);

        $this->reserve($this->vehicule($ville), $commune)
            ->assertStatus(400)
            ->assertJsonPath('error', 'hors_zone');
    }

    // --- Les communes du vehicule --------------------------------------------

    /**
     * Aucune commune cochee : toutes celles de la ville sont acceptees.
     *
     * Le proprietaire, le 16 octobre : « aucune commune selectionnee, on prend
     * toutes les communes ». C'est le defaut, et c'est ce qui permet de ne
     * rien saisir tant qu'on n'a pas besoin de restreindre.
     */
    public function test_sans_commune_cochee_toute_la_ville_est_acceptee(): void
    {
        $ville = City::factory()->create(['title' => 'Kinshasa']);
        $gombe = Town::factory()->create(['city_id' => $ville->id, 'rental_available' => true]);
        $limete = Town::factory()->create(['city_id' => $ville->id, 'rental_available' => true]);

        $vehicule = $this->vehicule($ville);

        $this->reserve($vehicule, $gombe)->assertSuccessful();
        $this->reserve($vehicule, $limete, jours: 5)->assertSuccessful();
    }

    /** Des communes cochees, le vehicule ne sort que de celles-la. */
    public function test_avec_des_communes_cochees_les_autres_sont_refusees(): void
    {
        $ville = City::factory()->create(['title' => 'Kinshasa']);
        $gombe = Town::factory()->create(['city_id' => $ville->id, 'title' => 'Gombe', 'rental_available' => true]);
        $limete = Town::factory()->create(['city_id' => $ville->id, 'title' => 'Limete', 'rental_available' => true]);

        $vehicule = $this->vehicule($ville);
        $vehicule->towns()->sync([$gombe->id]);

        $this->reserve($vehicule, $gombe)->assertSuccessful();

        $this->reserve($vehicule, $limete, jours: 5)
            ->assertStatus(400)
            ->assertJsonPath('error', 'hors_commune')
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Gombe'));
    }

    /**
     * La ville prime : une commune cochee d'une AUTRE ville ne la rouvre pas.
     *
     * Le cas vient d'une saisie en administration ou l'on change la ville sans
     * revoir les communes. Le refus de ville doit rester celui qu'on montre.
     */
    public function test_la_ville_prime_sur_les_communes_cochees(): void
    {
        $kinshasa = City::factory()->create(['title' => 'Kinshasa']);
        $lubumbashi = City::factory()->create(['title' => 'Lubumbashi']);
        $kinoise = Town::factory()->create(['city_id' => $kinshasa->id, 'rental_available' => true]);

        $vehicule = $this->vehicule($lubumbashi);
        $vehicule->towns()->sync([$kinoise->id]);

        $this->reserve($vehicule, $kinoise)
            ->assertStatus(400)
            ->assertJsonPath('error', 'hors_ville');
    }

    /** Le catalogue grise aussi le vehicule dont la commune n'est pas desservie. */
    public function test_le_catalogue_tient_compte_des_communes_du_vehicule(): void
    {
        $ville = City::factory()->create(['title' => 'Kinshasa']);
        $gombe = Town::factory()->create(['city_id' => $ville->id, 'rental_available' => true]);
        $limete = Town::factory()->create(['city_id' => $ville->id, 'rental_available' => true]);

        $vehicule = $this->vehicule($ville);
        $vehicule->towns()->sync([$gombe->id]);

        $this->getJson('/api/rental/vehicles?pickup_town_id='.$limete->id)
            ->assertSuccessful()
            ->assertJsonPath('data.0.reservable', false);

        $this->getJson('/api/rental/vehicles?pickup_town_id='.$gombe->id)
            ->assertSuccessful()
            ->assertJsonPath('data.0.reservable', true);
    }

    /** L'administration coche les communes depuis la fiche du vehicule. */
    public function test_l_admin_coche_les_communes_du_vehicule(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn () => true);
        /*
         * Sur le garde `web`, explicitement : `setUp` a deja ouvert une
         * session Sanctum pour le client, et sans nommer le garde, le panneau
         * Filament ne reconnait pas l'administrateur — la page ne se monte
         * alors pas, et l'echec ne dit rien de l'autorisation.
         */
        $this->actingAs($admin, 'web');

        $ville = City::factory()->create(['title' => 'Kinshasa']);
        $gombe = Town::factory()->create(['city_id' => $ville->id, 'title' => 'Gombe']);
        Town::factory()->create(['city_id' => $ville->id, 'title' => 'Limete']);

        $vehicule = $this->vehicule($ville);

        Livewire::test(EditVehicle::class, ['record' => $vehicule->getRouteKey()])
            ->fillForm(['towns' => [$gombe->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$gombe->id], $vehicule->fresh()->towns->pluck('id')->all());
    }

    // --- Le catalogue reste mondial -----------------------------------------

    /** On VOIT les vehicules des autres villes ; on ne peut pas les reserver. */
    public function test_le_catalogue_rend_les_vehicules_de_toutes_les_villes(): void
    {
        $kinshasa = City::factory()->create(['title' => 'Kinshasa']);
        $lubumbashi = City::factory()->create(['title' => 'Lubumbashi']);
        $this->vehicule($kinshasa);
        $this->vehicule($lubumbashi);

        $this->getJson('/api/rental/vehicles')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data');
    }

    /** Avec une commune de depart, chaque vehicule dit s'il est reservable. */
    public function test_le_catalogue_dit_quels_vehicules_sont_reservables(): void
    {
        $kinshasa = City::factory()->create(['title' => 'Kinshasa']);
        $lubumbashi = City::factory()->create(['title' => 'Lubumbashi']);
        $commune = Town::factory()->create(['city_id' => $kinshasa->id, 'rental_available' => true]);

        $kinois = $this->vehicule($kinshasa);
        $this->vehicule($lubumbashi);

        $reponse = $this->getJson('/api/rental/vehicles?pickup_town_id='.$commune->id)
            ->assertSuccessful();

        $reservables = collect($reponse->json('data'))
            ->mapWithKeys(fn ($v) => [$v['city']['title'] ?? '—' => $v['reservable']]);

        $this->assertTrue($reservables['Kinshasa']);
        $this->assertFalse($reservables['Lubumbashi']);
        $this->assertSame('Kinshasa', $kinois->fresh()->city->title);
    }

    /** Sans commune de depart, rien ne change : tout est reservable. */
    public function test_sans_commune_de_depart_tout_est_reservable(): void
    {
        $this->vehicule(City::factory()->create(['title' => 'Lubumbashi']));

        $this->getJson('/api/rental/vehicles')
            ->assertSuccessful()
            ->assertJsonPath('data.0.reservable', true);
    }

    /**
     * T128 — poser la zone desservie sur tout un lot.
     *
     * « Ajoute le bulk action pour selectionner la commune ou la ville a
     * desservir. » Ouvrir quinze fiches pour cocher les memes communes est
     * la corvee qui finit par ne pas etre faite, et un parc mal renseigne se
     * reserve de partout.
     */
    public function test_l_action_groupee_pose_les_communes_sur_plusieurs_vehicules(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn () => true);
        $this->actingAs($admin, 'web');

        $kinshasa = City::factory()->create(['title' => 'Kinshasa']);
        $gombe = Town::factory()->create(['title' => 'Gombe', 'city_id' => $kinshasa->id]);
        $limete = Town::factory()->create(['title' => 'Limete', 'city_id' => $kinshasa->id]);

        $premier = $this->vehicule($kinshasa);
        $second = $this->vehicule($kinshasa);

        Livewire::test(ListVehicles::class)
            ->callTableBulkAction(
                'zoneDesservie',
                [$premier->id, $second->id],
                ['mode' => 'ajouter', 'towns' => [$gombe->id, $limete->id]],
            )
            ->assertHasNoActionErrors();

        $this->assertEqualsCanonicalizing(
            [$gombe->id, $limete->id],
            $premier->fresh()->towns->pluck('id')->all(),
        );
        $this->assertCount(2, $second->fresh()->towns);
    }

    /**
     * LE GARDE-FOU : une commune n'est attachee qu'aux vehicules de SA ville.
     *
     * Sans lui, selectionner un parc de deux villes et cocher Gombe
     * rattacherait un vehicule de Lubumbashi a une commune de Kinshasa. Une
     * incoherence silencieuse, que seul un client hors zone revelerait.
     */
    public function test_une_commune_ne_part_pas_dans_une_autre_ville(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn () => true);
        $this->actingAs($admin, 'web');

        $kinshasa = City::factory()->create(['title' => 'Kinshasa']);
        $lubumbashi = City::factory()->create(['title' => 'Lubumbashi']);
        $gombe = Town::factory()->create(['title' => 'Gombe', 'city_id' => $kinshasa->id]);

        $kinois = $this->vehicule($kinshasa);
        $katangais = $this->vehicule($lubumbashi);

        Livewire::test(ListVehicles::class)
            ->callTableBulkAction(
                'zoneDesservie',
                [$kinois->id, $katangais->id],
                ['mode' => 'ajouter', 'towns' => [$gombe->id]],
            );

        $this->assertCount(1, $kinois->fresh()->towns);
        // Rien n'a ete pose sur celui de l'autre ville.
        $this->assertCount(0, $katangais->fresh()->towns);
    }

    /** « Remplacer » efface les communes precedentes, « vider » les retire toutes. */
    public function test_les_trois_modes_de_l_action_groupee(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn () => true);
        $this->actingAs($admin, 'web');

        $kinshasa = City::factory()->create(['title' => 'Kinshasa']);
        $gombe = Town::factory()->create(['title' => 'Gombe', 'city_id' => $kinshasa->id]);
        $limete = Town::factory()->create(['title' => 'Limete', 'city_id' => $kinshasa->id]);

        $vehicule = $this->vehicule($kinshasa);
        $vehicule->towns()->sync([$gombe->id]);

        Livewire::test(ListVehicles::class)
            ->callTableBulkAction(
                'zoneDesservie',
                [$vehicule->id],
                ['mode' => 'remplacer', 'towns' => [$limete->id]],
            );

        $this->assertSame([$limete->id], $vehicule->fresh()->towns->pluck('id')->all());

        Livewire::test(ListVehicles::class)
            ->callTableBulkAction(
                'zoneDesservie',
                [$vehicule->id],
                ['mode' => 'vider', 'towns' => []],
            );

        // Vide ne FERME rien : sans commune, le vehicule dessert toute sa
        // ville. C'est la doctrine de la fiche, et elle ne change pas ici.
        $this->assertCount(0, $vehicule->fresh()->towns);
        $this->assertTrue($vehicule->fresh()->dessertLaCommune($gombe->id));
    }
}
