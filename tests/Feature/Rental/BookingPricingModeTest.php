<?php

namespace Tests\Feature\Rental;

use App\Models\Currency;
use App\Models\Vehicle;
use App\Services\Rental\BookingPricing;
use App\Settings\RentalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * La location se facture a l'heure OU a la journee.
 *
 * Le client choisit, et chaque mode facture ses propres unites entamees. Il
 * n'y a pas de melange : trente heures coutent deux journees a la journee, et
 * trente heures a l'heure.
 */
class BookingPricingModeTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        RentalSettings::fake([
            'deposit_percentage' => 10.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
            'whatsapp_number' => '',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Nous ne desservons pas encore cette commune.',
        ], loadMissingValues: false);

        $currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        // Les tarifs du client : 10 $ l'heure, 149 $ la journee.
        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001',
            'hourly_rate' => 10, 'daily_rate' => 149,
            'currency_id' => $currency->id,
        ]);
    }

    private function chiffrer(string $debut, string $fin, string $mode): object
    {
        return app(BookingPricing::class)->quote(
            $this->vehicle,
            Carbon::parse($debut),
            Carbon::parse($fin),
            $mode,
        );
    }

    public function test_une_journee_coute_le_tarif_journalier(): void
    {
        /*
         * C'est tout l'interet du mode : vingt-quatre heures facturees a
         * l'heure couteraient 240 $, la ou la journee en coute 149.
         */
        $quote = $this->chiffrer('2026-11-01 08:00', '2026-11-02 08:00', BookingPricing::MODE_DAILY);

        $this->assertSame('daily', $quote->mode);
        $this->assertSame(149.0, $quote->unitPrice);
        $this->assertSame(1.0, $quote->billedUnits);
        $this->assertSame(149.0, $quote->total);
    }

    public function test_les_memes_heures_a_l_heure_coutent_bien_plus(): void
    {
        $quote = $this->chiffrer('2026-11-01 08:00', '2026-11-02 08:00', BookingPricing::MODE_HOURLY);

        $this->assertSame(24.0, $quote->billedUnits);
        $this->assertSame(240.0, $quote->total);
    }

    public function test_toute_journee_entamee_est_due(): void
    {
        // Trente heures : deux journees, pas une et quart.
        $quote = $this->chiffrer('2026-11-01 08:00', '2026-11-02 14:00', BookingPricing::MODE_DAILY);

        $this->assertSame(2.0, $quote->billedUnits);
        $this->assertSame(298.0, $quote->total);
    }

    public function test_une_course_courte_a_la_journee_coute_une_journee(): void
    {
        // Deux heures reservees a la journee restent une journee pleine.
        $quote = $this->chiffrer('2026-11-01 08:00', '2026-11-01 10:00', BookingPricing::MODE_DAILY);

        $this->assertSame(1.0, $quote->billedUnits);
        $this->assertSame(149.0, $quote->total);
    }

    public function test_l_acompte_suit_le_total_quel_que_soit_le_mode(): void
    {
        $quote = $this->chiffrer('2026-11-01 08:00', '2026-11-02 08:00', BookingPricing::MODE_DAILY);

        $this->assertSame(14.9, $quote->deposit);
        // Par soustraction : l'acompte et le solde doivent refaire le total au
        // centime, ce que deux pourcentages independants ne garantissent pas.
        $this->assertSame(134.1, $quote->balance);
        $this->assertSame($quote->total, round($quote->deposit + $quote->balance, 2));
    }

    public function test_un_vehicule_sans_tarif_journalier_refuse_le_mode(): void
    {
        $this->vehicle->update(['daily_rate' => null]);

        $this->expectException(InvalidArgumentException::class);

        $this->chiffrer('2026-11-01 08:00', '2026-11-02 08:00', BookingPricing::MODE_DAILY);
    }

    public function test_un_mode_inconnu_est_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->chiffrer('2026-11-01 08:00', '2026-11-01 10:00', 'a_la_semaine');
    }

    /** Le tarif horaire reste fige sur la reservation, a titre indicatif. */
    public function test_la_reservation_garde_le_mode_et_son_prix_unitaire(): void
    {
        $colonnes = $this->chiffrer(
            '2026-11-01 08:00',
            '2026-11-02 08:00',
            BookingPricing::MODE_DAILY,
        )->toBookingAttributes();

        $this->assertSame('daily', $colonnes['pricing_mode']);
        $this->assertSame(149.0, $colonnes['unit_price']);
        $this->assertSame(1.0, $colonnes['billed_units']);
        $this->assertSame(10.0, $colonnes['hourly_rate']);
        $this->assertSame(24.0, $colonnes['duration_hours']);
    }
}
