<?php

namespace Tests\Feature\Rental;

use App\Models\Booking;
use App\Models\Chauffeur;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Rental\VehicleAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Qui est libre, et quand. */
class VehicleAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Vehicle $vehicle;

    private VehicleAvailability $availability;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001', 'hourly_rate' => 10,
            'currency_id' => $this->currency->id,
        ]);

        $this->availability = app(VehicleAvailability::class);
    }

    private function booking(string $from, string $to, string $status = Booking::STATUS_CONFIRMED, array $extra = []): Booking
    {
        return Booking::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => Carbon::parse($from),
            'ends_at' => Carbon::parse($to),
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
            'status' => $status,
        ], $extra));
    }

    private function libre(string $from, string $to): bool
    {
        return $this->availability->isVehicleAvailable(
            $this->vehicle, Carbon::parse($from), Carbon::parse($to),
        );
    }

    public function test_un_creneau_confirme_bloque_le_chevauchement(): void
    {
        $this->booking('2026-10-01 10:00', '2026-10-01 15:00');

        $this->assertFalse($this->libre('2026-10-01 12:00', '2026-10-01 13:00'));
    }

    public function test_deux_creneaux_qui_se_suivent_sont_tous_deux_possibles(): void
    {
        $this->booking('2026-10-01 10:00', '2026-10-01 15:00');

        $this->assertTrue($this->libre('2026-10-01 15:00', '2026-10-01 18:00'));
        $this->assertTrue($this->libre('2026-10-01 07:00', '2026-10-01 10:00'));
    }

    public function test_une_reservation_non_payee_ne_bloque_personne(): void
    {
        // Le premier qui paie emporte le vehicule.
        $this->booking('2026-10-01 10:00', '2026-10-01 15:00', Booking::STATUS_PENDING_PAYMENT);

        $this->assertTrue($this->libre('2026-10-01 12:00', '2026-10-01 13:00'));
    }

    public function test_une_reservation_annulee_rend_le_creneau(): void
    {
        $this->booking('2026-10-01 10:00', '2026-10-01 15:00', Booking::STATUS_CANCELLED);

        $this->assertTrue($this->libre('2026-10-01 12:00', '2026-10-01 13:00'));
    }

    public function test_une_reservation_ne_se_chevauche_pas_elle_meme(): void
    {
        // Sans ca, editer l'heure d'une reservation existante depuis
        // l'administration serait toujours refuse.
        $booking = $this->booking('2026-10-01 10:00', '2026-10-01 15:00');

        $this->assertTrue($this->availability->isVehicleAvailable(
            $this->vehicle,
            Carbon::parse('2026-10-01 11:00'),
            Carbon::parse('2026-10-01 16:00'),
            $booking,
        ));
    }

    public function test_le_catalogue_ne_propose_pas_un_vehicule_pris(): void
    {
        $this->booking('2026-10-01 10:00', '2026-10-01 15:00');

        $libres = $this->availability->availableVehicles(
            Carbon::parse('2026-10-01 12:00'),
            Carbon::parse('2026-10-01 13:00'),
        );

        $this->assertCount(0, $libres);
    }

    public function test_un_vehicule_retire_du_parc_n_est_plus_propose(): void
    {
        $this->vehicle->update(['is_active' => false]);

        $libres = $this->availability->availableVehicles(
            Carbon::parse('2026-10-01 12:00'),
            Carbon::parse('2026-10-01 13:00'),
        );

        $this->assertCount(0, $libres);
    }

    public function test_un_chauffeur_ne_se_dedouble_pas(): void
    {
        // L'affecter a deux courses qui se chevauchent produit une course sans
        // conducteur le jour venu.
        $chauffeur = Chauffeur::query()->create([
            'user_id' => User::factory()->create()->id,
            'full_name' => 'Jean Kabila', 'phone' => '+243810000000',
        ]);

        $this->booking('2026-10-01 10:00', '2026-10-01 15:00', Booking::STATUS_CONFIRMED, [
            'chauffeur_id' => $chauffeur->id,
        ]);

        $this->assertFalse($this->availability->isChauffeurAvailable(
            $chauffeur,
            Carbon::parse('2026-10-01 12:00'),
            Carbon::parse('2026-10-01 13:00'),
        ));
    }
}
