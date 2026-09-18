<?php

namespace Tests\Feature\Rental;

use App\Models\Booking;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les regles portees par le modele de reservation.
 *
 * Elles decident qui obtient un vehicule : elles ne peuvent pas dependre du
 * bon vouloir de l'appelant.
 */
class BookingModelTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota',
            'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001',
            'hourly_rate' => 10,
            'currency_id' => $this->currency->id,
        ]);
    }

    private function booking(array $attributes = []): Booking
    {
        return Booking::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(15, 0),
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10,
            'duration_hours' => 5,
            'total' => 50,
            'deposit' => 5,
            'balance' => 45,
            'currency_id' => $this->currency->id,
        ], $attributes));
    }

    public function test_deux_creneaux_qui_se_touchent_ne_se_chevauchent_pas(): void
    {
        $this->booking();

        // 10h-15h puis 15h-18h : la seconde commence quand la premiere finit.
        $chevauchements = Booking::query()->overlapping(
            now()->addDay()->setTime(15, 0),
            now()->addDay()->setTime(18, 0),
        )->count();

        $this->assertSame(0, $chevauchements);
    }

    public function test_un_creneau_inclus_chevauche(): void
    {
        $this->booking();

        $chevauchements = Booking::query()->overlapping(
            now()->addDay()->setTime(12, 0),
            now()->addDay()->setTime(13, 0),
        )->count();

        $this->assertSame(1, $chevauchements);
    }

    public function test_une_reservation_non_payee_ne_retient_pas_le_vehicule(): void
    {
        // Le premier qui paie emporte le vehicule : tant qu'aucun acompte
        // n'est recu, le creneau reste ouvert a tout le monde.
        $this->booking(['status' => Booking::STATUS_PENDING_PAYMENT]);

        $this->assertSame(0, Booking::query()->holding()->count());
    }

    public function test_une_reservation_confirmee_retient_le_vehicule(): void
    {
        $this->booking(['status' => Booking::STATUS_CONFIRMED]);

        $this->assertSame(1, Booking::query()->holding()->count());
    }

    public function test_une_reservation_perdue_ne_retient_plus_rien(): void
    {
        // Le client a paye mais quelqu'un avait paye avant : son creneau doit
        // rester disponible pour le gagnant.
        $this->booking(['status' => Booking::STATUS_LOST]);

        $this->assertSame(0, Booking::query()->holding()->count());
    }

    public function test_la_date_d_annulation_entraine_le_statut(): void
    {
        $booking = $this->booking(['status' => Booking::STATUS_CONFIRMED]);

        $booking->update(['cancelled_at' => now()]);

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
    }

    public function test_le_statut_annule_entraine_la_date(): void
    {
        $booking = $this->booking(['status' => Booking::STATUS_CONFIRMED]);

        $booking->update(['status' => Booking::STATUS_CANCELLED]);

        $this->assertNotNull($booking->fresh()->cancelled_at);
    }

    public function test_le_code_de_prise_en_charge_ne_sort_pas_par_defaut(): void
    {
        // Le chauffeur ne doit jamais le recevoir : sans cette regle il
        // pourrait declarer une course qu'il n'a pas faite.
        $booking = $this->booking(['pickup_code' => '4821']);

        $this->assertArrayNotHasKey('pickup_code', $booking->toArray());
    }

    public function test_une_reservation_commencee_n_est_plus_annulable(): void
    {
        $booking = $this->booking([
            'status' => Booking::STATUS_CONFIRMED,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->assertFalse($booking->isCancellable());
    }

    public function test_une_reservation_a_venir_est_annulable(): void
    {
        $booking = $this->booking(['status' => Booking::STATUS_CONFIRMED]);

        $this->assertTrue($booking->isCancellable());
    }
}
