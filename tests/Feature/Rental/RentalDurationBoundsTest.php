<?php

namespace Tests\Feature\Rental;

use App\Exceptions\BookingRefusee;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Rental\BookingPricing;
use App\Settings\RentalSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Les bornes de duree : ce que l'application lit, et ce que l'API refuse.
 *
 * Le plafond horaire ne vivait que dans le mobile : l'API acceptait une course
 * de 24 heures facturee au tarif horaire, la ou la journee coute bien moins.
 * C'est le meme motif que la faille de prix deja rencontree en production.
 */
class RentalDurationBoundsTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private User $client;

    /** @param array<string, mixed> $overrides */
    private function reglages(array $overrides = []): void
    {
        /*
         * `loadMissingValues: false` est indispensable : une autre suite vide
         * la table `settings`, et sans ce drapeau le test passe seul mais
         * echoue dans la suite complete. La liste doit donc etre complete.
         */
        RentalSettings::fake(array_merge([
            'deposit_percentage' => 10.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
            'hours_min' => 1,
            'hours_max' => 23,
            'days_min' => 1,
            'days_max' => 30,
            'whatsapp_number' => '',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Nous ne desservons pas encore cette commune.',
        ], $overrides), loadMissingValues: false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reglages();

        $currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001', 'hourly_rate' => 10,
            'daily_rate' => 120, 'currency_id' => $currency->id,
        ]);

        $this->client = User::factory()->create();

        Sanctum::actingAs($this->client, ['*']);
    }

    private function reserve(string $from, string $to, string $mode = BookingPricing::MODE_HOURLY): TestResponse
    {
        return $this->postJson('/api/rental/bookings', [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'starts_at' => Carbon::parse($from)->toDateTimeString(),
            'ends_at' => Carbon::parse($to)->toDateTimeString(),
            'pricing_mode' => $mode,
            'pickup_location' => 'Gombe',
        ]);
    }

    /** L'application lit les quatre bornes, elle ne les porte plus en dur. */
    public function test_la_configuration_expose_les_bornes(): void
    {
        $this->getJson('/api/rental/config')
            ->assertSuccessful()
            ->assertJsonPath('data.duree.hours_min', 1)
            ->assertJsonPath('data.duree.hours_max', 23)
            ->assertJsonPath('data.duree.days_min', 1)
            ->assertJsonPath('data.duree.days_max', 30);
    }

    /**
     * Vingt-quatre heures a l'heure sont refusees, pas rebasculees en journee.
     *
     * Le client a choisi un mode et un prix : lui en appliquer un autre
     * reviendrait a decider du montant a sa place.
     */
    public function test_vingt_quatre_heures_a_l_heure_sont_refusees(): void
    {
        $this->reserve('+2 days 10:00', '+3 days 10:00')
            ->assertStatus(400)
            ->assertJsonPath('error', 'duree_hors_bornes')
            ->assertJsonPath('message', 'La durée doit être comprise entre 1 et 23 heures.');

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_vingt_trois_heures_a_l_heure_passent(): void
    {
        $this->reserve('+2 days 10:00', '+3 days 09:00')->assertSuccessful();

        $this->assertSame(23.0, (float) Booking::query()->value('billed_units'));
    }

    public function test_trente_et_un_jours_sont_refuses(): void
    {
        $this->reserve('+2 days 10:00', '+33 days 10:00', BookingPricing::MODE_DAILY)
            ->assertStatus(400)
            ->assertJsonPath('error', 'duree_hors_bornes')
            ->assertJsonPath('message', 'La durée doit être comprise entre 1 et 30 jours.');

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_une_journee_passe(): void
    {
        $this->reserve('+2 days 10:00', '+3 days 10:00', BookingPricing::MODE_DAILY)
            ->assertSuccessful();

        $this->assertSame(1.0, (float) Booking::query()->value('billed_units'));
    }

    /**
     * Le modele tient la borne, quel que soit le chemin.
     *
     * L'API et le formulaire refusent plus tot et plus proprement ; ce garde
     * est le dernier, celui qui vaut pour un import, une commande artisan ou
     * un futur appelant qui ne passerait par aucun des deux.
     */
    public function test_le_modele_refuse_une_ecriture_hors_bornes(): void
    {
        $this->expectException(BookingRefusee::class);

        Booking::query()->create([
            'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-02 10:00:00',
            'pickup_location' => 'Gombe',
        ]);
    }

    /**
     * Une reservation ancienne reste modifiable.
     *
     * Les lignes prises avant la regle sortent des bornes : bloquer leur
     * changement de statut rendrait impossible de les terminer ou de les
     * annuler, et le garde ferait plus de degats que le defaut qu'il corrige.
     */
    public function test_une_reservation_hors_bornes_deja_en_base_reste_modifiable(): void
    {
        $id = DB::table('bookings')->insertGetId([
            'reference' => 'R-ANCIENNE', 'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-03 10:00:00',
            'pickup_location' => 'Gombe',
            'status' => Booking::STATUS_CONFIRMED,
            'hourly_rate' => 10, 'duration_hours' => 48,
            'total' => 480, 'deposit' => 96, 'balance' => 384,
            'currency_id' => $this->vehicle->currency_id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Booking::query()->whereKey($id)->first()->update([
            'status' => Booking::STATUS_COMPLETED,
        ]);

        $this->assertSame(Booking::STATUS_COMPLETED, Booking::query()->whereKey($id)->value('status'));
    }

    /**
     * La borne vient bien des reglages.
     *
     * Sans ce test, un plafond ecrit en dur dans le service passerait tous les
     * autres : c'est justement le defaut qu'on corrige.
     */
    public function test_abaisser_le_plafond_horaire_change_le_refus(): void
    {
        $this->reglages(['hours_max' => 5]);

        $this->reserve('+2 days 10:00', '+2 days 16:00')
            ->assertStatus(400)
            ->assertJsonPath('error', 'duree_hors_bornes')
            ->assertJsonPath('message', 'La durée doit être comprise entre 1 et 5 heures.');

        $this->reserve('+2 days 10:00', '+2 days 15:00')->assertSuccessful();
    }
}
