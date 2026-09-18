<?php

namespace Tests\Feature\Rental;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Chauffeur;
use App\Models\Currency;
use App\Models\PaimentMethod;
use App\Models\StatusPayement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Rental\BookingWorkflow;
use App\Settings\RentalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Le cycle de vie d'une reservation, de la creation a l'encaissement.
 *
 * Ces transitions sont partagees par l'administration aujourd'hui et par
 * l'API demain : les ecrire deux fois ferait diverger ce que le back-office
 * enregistre de ce que le client voit.
 */
class BookingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Vehicle $vehicle;

    private Chauffeur $chauffeur;

    protected function setUp(): void
    {
        parent::setUp();

        RentalSettings::fake([
            'deposit_percentage' => 10.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
        ], loadMissingValues: false);

        $this->currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        StatusPayement::query()->create([
            'code' => '0', 'name' => 'Transaction traitée', 'is_paid' => true, 'is_default' => false,
        ]);

        PaimentMethod::query()->create(['title' => 'Espèces', 'slug' => 'especes', 'is_active' => true]);
        $mpesa = PaimentMethod::query()->create(['title' => 'Mpesa', 'slug' => 'mpesa', 'is_active' => true]);

        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001', 'hourly_rate' => 10,
            'currency_id' => $this->currency->id,
        ]);

        $this->chauffeur = Chauffeur::query()->create([
            'user_id' => User::factory()->create()->id,
            'full_name' => 'Jean Kabila', 'phone' => '+243810000000',
        ]);

        $this->mpesaId = $mpesa->id;
    }

    private int $mpesaId;

    private function booking(): Booking
    {
        return Booking::query()->create([
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => Carbon::parse('2026-10-01 10:00'),
            'ends_at' => Carbon::parse('2026-10-01 15:00'),
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
        ]);
    }

    private function workflow(): BookingWorkflow
    {
        return app(BookingWorkflow::class);
    }

    public function test_le_parcours_complet_de_la_reservation_a_l_encaissement(): void
    {
        $booking = $this->booking();

        // 1. L'acompte de 10 % est encaisse.
        $this->assertTrue($this->workflow()->payDeposit($booking, $this->mpesaId, 'REF-1', '+243810000000'));
        $booking->refresh();
        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->status);
        $this->assertNotNull($booking->pickup_code);

        // 2. Un chauffeur est affecte.
        $this->workflow()->assignChauffeur($booking, $this->chauffeur);
        $this->assertSame($this->chauffeur->id, $booking->refresh()->chauffeur_id);

        // 3. Le client monte.
        $this->workflow()->startRide($booking);
        $booking->refresh();
        $this->assertSame(Booking::STATUS_IN_PROGRESS, $booking->status);
        $this->assertSame($this->chauffeur->id, $booking->picked_up_by);

        // 4. Le solde est remis en especes.
        $this->workflow()->collectBalance($booking);
        $booking->refresh();
        $this->assertSame(Booking::STATUS_COMPLETED, $booking->status);
        $this->assertNotNull($booking->balance_collected_at);
        $this->assertSame($this->chauffeur->id, $booking->balance_collected_by);

        // Les deux encaissements ont laisse leur trace : sans elles, l'argent
        // remis au chauffeur n'aurait aucun justificatif.
        $this->assertSame(2, $booking->payments()->count());
        $this->assertSame('5.00', $booking->payments()->where('kind', BookingPayment::KIND_DEPOSIT)->value('amount'));
        $this->assertSame('45.00', $booking->payments()->where('kind', BookingPayment::KIND_BALANCE)->value('amount'));
    }

    public function test_le_solde_est_encaisse_en_especes(): void
    {
        $booking = $this->booking();
        $this->workflow()->payDeposit($booking, $this->mpesaId);
        $this->workflow()->assignChauffeur($booking, $this->chauffeur);
        $this->workflow()->startRide($booking);
        $this->workflow()->collectBalance($booking);

        $solde = $booking->payments()->where('kind', BookingPayment::KIND_BALANCE)->first();

        $this->assertSame('especes', $solde->paimentMethod->slug);
        $this->assertSame($this->chauffeur->id, $solde->recorded_by);
    }

    public function test_une_annulation_rembourse_ce_qui_a_ete_paye(): void
    {
        $booking = $this->booking();
        $this->workflow()->payDeposit($booking, $this->mpesaId);

        $this->workflow()->cancel($booking, 'Client injoignable');

        $booking->refresh();
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        $this->assertSame('Client injoignable', $booking->cancellation_reason);
        $this->assertSame('5.00', $booking->refund_amount);
        $this->assertNull($booking->refunded_at);
    }

    public function test_une_annulation_sans_paiement_ne_doit_rien(): void
    {
        // Un client qui n'a jamais rien verse n'a rien a se voir rendre.
        $booking = $this->booking();

        $this->workflow()->cancel($booking);

        $this->assertNull($booking->refresh()->refund_amount);
    }

    public function test_le_remboursement_suit_le_pourcentage_configure(): void
    {
        RentalSettings::fake([
            'deposit_percentage' => 10.0,
            'refund_percentage' => 50.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
        ], loadMissingValues: false);

        $booking = $this->booking();
        $this->workflow()->payDeposit($booking, $this->mpesaId);
        $this->workflow()->cancel($booking);

        $this->assertSame('2.50', $booking->refresh()->refund_amount);
    }

    public function test_le_perdant_de_la_course_garde_la_trace_de_son_paiement(): void
    {
        $gagnant = $this->booking();
        $perdant = $this->booking();

        $this->workflow()->payDeposit($gagnant, $this->mpesaId, 'REF-GAGNANT');
        $this->assertFalse($this->workflow()->payDeposit($perdant, $this->mpesaId, 'REF-PERDANT'));

        $perdant->refresh();
        $this->assertSame(Booking::STATUS_LOST, $perdant->status);
        // Seule trace permettant de rendre l'argent.
        $this->assertSame('REF-PERDANT', $perdant->payments()->value('reference'));
        $this->assertSame('5.00', $perdant->refund_amount);
    }

    public function test_le_deblocage_du_code_remet_le_compteur_a_zero(): void
    {
        $booking = $this->booking();
        $booking->update(['pickup_code_attempts' => 3, 'pickup_code_locked_at' => now()]);

        $this->workflow()->unlockPickupCode($booking);

        $booking->refresh();
        $this->assertNull($booking->pickup_code_locked_at);
        $this->assertSame(0, $booking->pickup_code_attempts);
    }
}
