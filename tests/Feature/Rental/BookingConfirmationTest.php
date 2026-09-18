<?php

namespace Tests\Feature\Rental;

use App\Models\Booking;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Rental\BookingConfirmation;
use App\Settings\RentalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La course a la reservation.
 *
 * Une reservation non payee ne retient rien : plusieurs clients visent le meme
 * creneau, et l'argent est debite avant que le serveur sache qui gagne. Le
 * premier acompte recu emporte le vehicule ; le second repart avec un
 * remboursement du.
 */
class BookingConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withSettings();

        $this->currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001', 'hourly_rate' => 10,
            'currency_id' => $this->currency->id,
        ]);
    }


    /**
     * Les reglages sont declares par le test, pas lus en base.
     *
     * Ils vivent dans la table `settings`, qu'un autre test de la suite
     * tronque. Dependre de son contenu rendrait ces tests verts seuls et
     * rouges ensemble — et surtout, un test qui verifie un calcul doit dire
     * lui-meme sur quelles valeurs il s'appuie.
     */
    private function withSettings(array $overrides = []): void
    {
        RentalSettings::fake(array_merge([
            'deposit_percentage' => 10.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
        ], $overrides), loadMissingValues: false);
    }

    /**
     * Resolu a chaque appel, jamais memorise.
     *
     * Le service tient les reglages recus a sa construction : le garder dans
     * une propriete fixee au setUp ferait ignorer tout reglage change par un
     * test, en silence.
     */
    private function confirmation(): BookingConfirmation
    {
        return app(BookingConfirmation::class);
    }

    private function pendingBooking(): Booking
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

    public function test_le_premier_a_payer_obtient_le_vehicule(): void
    {
        $booking = $this->pendingBooking();

        $this->assertTrue($this->confirmation()->settleDeposit($booking, 5.0));

        $booking->refresh();
        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->status);
        $this->assertNotNull($booking->confirmed_at);
    }

    public function test_le_second_le_rate_et_se_voit_devoir_un_remboursement(): void
    {
        $gagnant = $this->pendingBooking();
        $perdant = $this->pendingBooking();

        $this->confirmation()->settleDeposit($gagnant, 5.0);

        $this->assertFalse($this->confirmation()->settleDeposit($perdant, 5.0));

        $perdant->refresh();
        $this->assertSame(Booking::STATUS_LOST, $perdant->status);
        // Le remboursement est calcule et enregistre ; le virement reste
        // manuel en V1.
        $this->assertSame('5.00', $perdant->refund_amount);
        $this->assertNull($perdant->refunded_at);
    }

    public function test_le_perdant_ne_retient_plus_le_creneau(): void
    {
        $gagnant = $this->pendingBooking();
        $perdant = $this->pendingBooking();

        $this->confirmation()->settleDeposit($gagnant, 5.0);
        $this->confirmation()->settleDeposit($perdant, 5.0);

        // Un seul des deux occupe le vehicule.
        $this->assertSame(1, Booking::query()->holding()->count());
    }

    public function test_le_code_de_prise_en_charge_n_existe_qu_apres_paiement(): void
    {
        $booking = $this->pendingBooking();

        // Avant le paiement il n'y a rien a prouver, et un code qui circule
        // tot est un code de plus a proteger.
        $this->assertNull($booking->pickup_code);

        $this->confirmation()->settleDeposit($booking, 5.0);

        $this->assertMatchesRegularExpression('/^\d{4}$/', $booking->refresh()->pickup_code);
    }

    public function test_un_creneau_voisin_reste_confirmable(): void
    {
        $premier = $this->pendingBooking();
        $this->confirmation()->settleDeposit($premier, 5.0);

        $suivant = $this->pendingBooking();
        $suivant->update([
            'starts_at' => Carbon::parse('2026-10-01 15:00'),
            'ends_at' => Carbon::parse('2026-10-01 18:00'),
        ]);

        $this->assertTrue($this->confirmation()->settleDeposit($suivant, 5.0));
    }

    public function test_le_remboursement_suit_le_pourcentage_configure(): void
    {
        $this->withSettings(['refund_percentage' => 50.0]);

        $gagnant = $this->pendingBooking();
        $perdant = $this->pendingBooking();

        $this->confirmation()->settleDeposit($gagnant, 5.0);
        $this->confirmation()->settleDeposit($perdant, 5.0);

        $this->assertSame('2.50', $perdant->refresh()->refund_amount);
    }
}
