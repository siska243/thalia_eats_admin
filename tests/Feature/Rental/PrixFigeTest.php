<?php

namespace Tests\Feature\Rental;

use App\Filament\Resources\BookingResource\Pages\EditBooking;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\RentalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le prix d'une reservation est FIGE a la reservation.
 *
 * Le proprietaire, le 16 octobre : « quand on selectionne, il faut que le bon
 * prix soit enregistre dans la location, et non quand on change le prix du
 * vehicule ça impacte aussi le prix de la location deja en cours ».
 *
 * Le defaut etait reel : l'ecran d'administration refait le chiffrage a
 * CHAQUE enregistrement, a partir du tarif actuel du vehicule. Ouvrir une
 * reservation d'hier et la sauver sans rien changer suffisait a la
 * reprendre au nouveau prix — et le client avait deja paye son acompte sur
 * l'ancien.
 */
class PrixFigeTest extends TestCase
{
    use RefreshDatabase;

    private Currency $devise;

    private Vehicle $vehicule;

    protected function setUp(): void
    {
        parent::setUp();

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
        Gate::before(fn () => true);
        $this->actingAs($admin, 'web');

        $this->devise = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->vehicule = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Corolla', 'plate_number' => 'KIN-5001',
            'hourly_rate' => 10, 'currency_id' => $this->devise->id, 'is_active' => true,
        ])->fresh();
    }

    private function reservation(): Booking
    {
        return Booking::query()->create([
            'reference' => 'R-'.uniqid(),
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicule->id,
            'status' => Booking::STATUS_CONFIRMED,
            'starts_at' => Carbon::parse('+2 days 10:00'),
            'ends_at' => Carbon::parse('+2 days 13:00'),
            'pricing_mode' => 'hourly',
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10,
            'unit_price' => 10,
            'billed_units' => 3,
            'duration_hours' => 3,
            'base_price' => 30,
            'total' => 30,
            'deposit' => 3,
            'balance' => 27,
            'currency_id' => $this->devise->id,
        ]);
    }

    private function enregistrer(Booking $reservation, array $champs = []): void
    {
        $page = Livewire::test(EditBooking::class, ['record' => $reservation->getRouteKey()]);

        // `fillForm([])` vide le formulaire au lieu de le laisser tel quel :
        // on ne l'appelle que s'il y a vraiment quelque chose a changer.
        if ($champs) {
            $page->fillForm($champs);
        }

        $page->call('save')->assertHasNoFormErrors();
    }

    /** Le cas signale : changer le tarif du vehicule ne touche pas l'existant. */
    public function test_changer_le_tarif_du_vehicule_ne_touche_pas_une_reservation(): void
    {
        $reservation = $this->reservation();

        $this->vehicule->update(['hourly_rate' => 99]);

        $this->enregistrer($reservation);

        $relue = $reservation->fresh();

        $this->assertSame(10.0, (float) $relue->unit_price);
        $this->assertSame(30.0, (float) $relue->total);
        $this->assertSame(3.0, (float) $relue->deposit);
    }

    /**
     * Le creneau change : les UNITES changent, le tarif reste.
     *
     * Rallonger une course deja convenue ne doit pas la reprendre au tarif du
     * jour : c'est le prix annonce au client qui fait foi.
     */
    public function test_allonger_le_creneau_garde_le_tarif_convenu(): void
    {
        $reservation = $this->reservation();

        $this->vehicule->update(['hourly_rate' => 99]);

        $this->enregistrer($reservation, [
            'ends_at' => Carbon::parse('+2 days 15:00')->toDateTimeString(),
        ]);

        $relue = $reservation->fresh();

        $this->assertSame(10.0, (float) $relue->unit_price);
        $this->assertSame(5.0, (float) $relue->billed_units);
        $this->assertSame(50.0, (float) $relue->total);
    }

    /**
     * Changer de VEHICULE refait le prix.
     *
     * Ce n'est plus la meme offre : garder le tarif de l'ancien vehicule
     * facturerait une berline au prix d'une citadine.
     */
    public function test_changer_de_vehicule_refait_le_prix(): void
    {
        $reservation = $this->reservation();

        $autre = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser', 'plate_number' => 'KIN-5002',
            'hourly_rate' => 40, 'currency_id' => $this->devise->id, 'is_active' => true,
        ])->fresh();

        $this->enregistrer($reservation, ['vehicle_id' => $autre->id]);

        $relue = $reservation->fresh();

        // Le changement a-t-il seulement ete enregistre ?
        $this->assertSame($autre->id, $relue->vehicle_id);
        $this->assertSame(40.0, (float) $relue->unit_price);
        $this->assertSame(120.0, (float) $relue->total);
    }
}
