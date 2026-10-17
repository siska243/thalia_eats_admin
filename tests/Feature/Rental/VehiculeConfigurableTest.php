<?php

namespace Tests\Feature\Rental;

use App\Filament\Resources\VehicleResource\Pages\EditVehicle;
use App\Models\CourseType;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Rental\BookingPricing;
use App\Settings\RentalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ce qu'un vehicule regle pour lui-meme.
 *
 * Le proprietaire, le 16 octobre : « en gros tout ce qui est possible de faire
 * pour une location de vehicule doit etre configurable », puis « quand on
 * coche propose a la location il faut mettre le prix et donner au client la
 * possibilite de louer ».
 *
 * Quatre reglages, tous facultatifs : un vehicule qui ne dit rien garde
 * exactement le comportement d'aujourd'hui, et le parc existant n'a rien a
 * saisir.
 */
class VehiculeConfigurableTest extends TestCase
{
    use RefreshDatabase;

    private Currency $devise;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * `loadMissingValues: false` : une autre suite vide la table des
         * reglages, et sans ce drapeau le test passe seul puis echoue en
         * suite complete.
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

        $this->devise = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);
    }

    /**
     * `fresh()` : les valeurs par defaut des colonnes sont posees par la BASE,
     * pas par le modele. Relire est le seul moyen de voir ce que le serveur
     * verra. En production la question ne se pose pas : le vehicule vient
     * toujours d'une lecture.
     */
    private function admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn () => true);
        // Le garde est nomme : sans cela le panneau Filament ne reconnait pas
        // l'administrateur et la page ne se monte pas.
        $this->actingAs($admin, 'web');
    }

    private function vehicule(array $attributs = []): Vehicle
    {
        return Vehicle::query()->create(array_merge([
            'brand' => 'Toyota', 'model' => 'Corolla',
            'plate_number' => 'KIN-'.uniqid(),
            'hourly_rate' => 20, 'daily_rate' => 150,
            'currency_id' => $this->devise->id,
        ], $attributs))->fresh();
    }

    private function prix(): BookingPricing
    {
        return app(BookingPricing::class);
    }

    // --- Ce que le vehicule propose ------------------------------------------

    // --- L'acompte propre au vehicule ----------------------------------------

    /** Sans acompte propre, celui des reglages s'applique : 10 %. */
    public function test_sans_acompte_propre_le_reglage_general_s_applique(): void
    {
        $devis = $this->prix()->quote(
            $this->vehicule(),
            Carbon::parse('+2 days 10:00'),
            Carbon::parse('+2 days 13:00'),
        );

        $this->assertSame(60.0, $devis->total);
        $this->assertSame(6.0, $devis->deposit);
    }

    /** Avec un acompte propre, c'est lui qui compte. */
    public function test_l_acompte_du_vehicule_remplace_le_reglage(): void
    {
        $devis = $this->prix()->quote(
            $this->vehicule(['deposit_percentage' => 50]),
            Carbon::parse('+2 days 10:00'),
            Carbon::parse('+2 days 13:00'),
        );

        $this->assertSame(60.0, $devis->total);
        $this->assertSame(30.0, $devis->deposit);
        // Le solde se deduit du total : deux arrondis independants ne se
        // rejoignent pas toujours.
        $this->assertSame(30.0, $devis->balance);
    }

    // --- Le parking par vehicule ---------------------------------------------

    /** Sans tarif propre, celui du type s'applique. */
    public function test_le_parking_du_type_s_applique_par_defaut(): void
    {
        $type = CourseType::query()->updateOrCreate(
            ['code' => 'C'],
            ['title' => 'Aeroport', 'parking_option' => true, 'parking_hourly_fee' => 10],
        );

        $devis = $this->prix()->quote(
            $this->vehicule(),
            Carbon::parse('+2 days 10:00'),
            Carbon::parse('+2 days 13:00'),
            BookingPricing::MODE_HOURLY,
            $type,
            parking: true,
        );

        $this->assertSame(30.0, $devis->parkingFee);
    }

    /** Le tarif saisi sur le vehicule remplace celui du type. */
    public function test_le_parking_du_vehicule_remplace_celui_du_type(): void
    {
        $type = CourseType::query()->updateOrCreate(
            ['code' => 'C'],
            ['title' => 'Aeroport', 'parking_option' => true, 'parking_hourly_fee' => 10],
        );

        $vehicule = $this->vehicule();
        $vehicule->coursePrices()->create([
            'course_type_id' => $type->id, 'parking_hourly_fee' => 15,
        ]);

        $devis = $this->prix()->quote(
            $vehicule->fresh(),
            Carbon::parse('+2 days 10:00'),
            Carbon::parse('+2 days 13:00'),
            BookingPricing::MODE_HOURLY,
            $type,
            parking: true,
        );

        $this->assertSame(45.0, $devis->parkingFee);
    }

    /**
     * ZERO veut dire « offert », et ce n'est pas la meme chose que vide.
     *
     * Un tarif nul laisse le type decider ; un zero saisi dit que le client ne
     * paie pas. Sans cette distinction, offrir le parking serait impossible.
     */
    public function test_un_parking_a_zero_est_offert(): void
    {
        $type = CourseType::query()->updateOrCreate(
            ['code' => 'C'],
            ['title' => 'Aeroport', 'parking_option' => true, 'parking_hourly_fee' => 10],
        );

        $vehicule = $this->vehicule();
        $vehicule->coursePrices()->create([
            'course_type_id' => $type->id, 'parking_hourly_fee' => 0,
        ]);

        $devis = $this->prix()->quote(
            $vehicule->fresh(),
            Carbon::parse('+2 days 10:00'),
            Carbon::parse('+2 days 13:00'),
            BookingPricing::MODE_HOURLY,
            $type,
            parking: true,
        );

        $this->assertSame(0.0, $devis->parkingFee);
    }

    // --- L'ecran d'administration --------------------------------------------

    /** Les modalites se saisissent sur la fiche. */
    public function test_l_admin_saisit_la_duree_minimale_et_l_acompte(): void
    {
        $this->admin();

        $vehicule = $this->vehicule();

        Livewire::test(EditVehicle::class, ['record' => $vehicule->getRouteKey()])
            ->fillForm(['minimum_hours' => 48, 'deposit_percentage' => 30])
            ->call('save')
            ->assertHasNoFormErrors();

        $relu = $vehicule->fresh();

        $this->assertSame(48, $relu->minimum_hours);
        $this->assertSame(30.0, (float) $relu->deposit_percentage);
    }

    // --- Ce que le client recoit ---------------------------------------------

    /** Le catalogue dit tout ce qui decide de la location. */
    public function test_le_catalogue_annonce_les_reglages_du_vehicule(): void
    {
        /*
         * Sans tarif horaire, le vehicule ne se loue qu'a la journee : c'est
         * le TARIF qui le dit, il n'y a pas de case a cocher. « Si le prix est
         * defini et superieur a zero, la location s'active. »
         */
        $this->vehicule([
            'minimum_hours' => 48,
            'deposit_percentage' => 30,
            'hourly_rate' => 0,
        ]);

        $this->getJson('/api/rental/vehicles')
            ->assertSuccessful()
            ->assertJsonPath('data.0.allows_hourly', false)
            ->assertJsonPath('data.0.allows_daily', true)
            ->assertJsonPath('data.0.minimum_hours', 48)
            ->assertJsonPath('data.0.deposit_percentage', fn ($v) => (float) $v === 30.0);
    }

    /** Un vehicule qui ne regle rien annonce les valeurs generales. */
    public function test_un_vehicule_sans_reglage_annonce_le_general(): void
    {
        $this->vehicule();

        $this->getJson('/api/rental/vehicles')
            ->assertSuccessful()
            ->assertJsonPath('data.0.allows_hourly', true)
            ->assertJsonPath('data.0.minimum_hours', null)
            ->assertJsonPath('data.0.deposit_percentage', fn ($v) => (float) $v === 10.0);
    }
}
