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
 * Le prix vient du serveur.
 *
 * Le client envoie un creneau, jamais un montant : c'est la doctrine deja
 * posee pour les repas, ou laisser le prix venir du client avait ouvert une
 * faille de paiement.
 */
class BookingPricingTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withSettings();

        $currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota',
            'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001',
            'hourly_rate' => 10,
            'currency_id' => $currency->id,
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

    private function quote(string $from, string $to)
    {
        return app(BookingPricing::class)->quote(
            $this->vehicle,
            Carbon::parse($from),
            Carbon::parse($to),
        );
    }

    public function test_l_exemple_du_devis(): void
    {
        // « Un vehicule a 10 $ de l'heure, reserve de 10h00 a 15h00. Cela fait
        // 5 heures, donc 50 $. Le client paie 5 $ au moment de reserver, il lui
        // reste 45 $ a regler. »
        $quote = $this->quote('2026-10-01 10:00', '2026-10-01 15:00');

        $this->assertSame(5.0, $quote->durationHours);
        $this->assertSame(50.0, $quote->total);
        $this->assertSame(5.0, $quote->deposit);
        $this->assertSame(45.0, $quote->balance);
    }

    public function test_toute_heure_entamee_est_due(): void
    {
        // 10h00 a 15h30 : cinq heures et demie se facturent six.
        $quote = $this->quote('2026-10-01 10:00', '2026-10-01 15:30');

        $this->assertSame(6.0, $quote->durationHours);
        $this->assertSame(60.0, $quote->total);
    }

    public function test_une_course_courte_est_facturee_au_minimum(): void
    {
        // Vingt minutes se facturent une heure.
        $quote = $this->quote('2026-10-01 10:00', '2026-10-01 10:20');

        $this->assertSame(1.0, $quote->durationHours);
        $this->assertSame(10.0, $quote->total);
    }

    public function test_l_acompte_et_le_solde_font_toujours_le_total(): void
    {
        // Le solde est obtenu par soustraction : deux arrondis independants ne
        // se rejoignent pas toujours, et le client paierait un centime de trop.
        $this->vehicle->update(['hourly_rate' => 3.33]);

        $quote = $this->quote('2026-10-01 10:00', '2026-10-01 13:00');

        $this->assertSame($quote->total, round($quote->deposit + $quote->balance, 2));
    }

    public function test_une_fin_avant_le_debut_est_refusee(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->quote('2026-10-01 15:00', '2026-10-01 10:00');
    }
}
