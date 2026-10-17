<?php

namespace Tests\Feature\Rental;

use App\Exceptions\BookingRefusee;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\RentalSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le delai de prevenance : on ne commande pas une course pour tout de suite.
 *
 * Rien n'empechait un client de reserver un depart passe, ou dans la minute.
 * Personne ne peut honorer cela : il faut joindre un chauffeur et le faire
 * rouler. Le delai est commercial, il vit donc dans les reglages, et le
 * serveur l'applique — l'ecran seul ne protegerait que l'ecran.
 */
class RentalDelaiPrevenanceTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private User $client;

    /** @param array<string, mixed> $overrides */
    private function reglages(array $overrides = []): void
    {
        /*
         * `loadMissingValues: false` impose la liste complete : une autre
         * suite vide la table `settings`, et un reglage oublie ici passe seul
         * mais tombe dans la suite entiere.
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
            'lead_time_minutes' => 30,
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

    /** Une course de deux heures demarrant au moment donne. */
    private function reserve(Carbon $depart): TestResponse
    {
        return $this->postJson('/api/rental/bookings', [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'starts_at' => $depart->toDateTimeString(),
            'ends_at' => $depart->copy()->addHours(2)->toDateTimeString(),
            'pickup_location' => 'Gombe',
        ]);
    }

    /** L'application lit le delai, elle ne le porte pas en dur. */
    public function test_la_configuration_expose_le_delai(): void
    {
        $this->getJson('/api/rental/config')
            ->assertSuccessful()
            ->assertJsonPath('data.duree.lead_time_minutes', 30);
    }

    public function test_un_depart_passe_est_refuse(): void
    {
        $this->reserve(now()->subHour())
            ->assertStatus(400)
            ->assertJsonPath('error', BookingRefusee::DELAI_PREVENANCE)
            ->assertJsonPath('message', "Le départ doit être prévu au moins 30 minutes à l'avance.");

        $this->assertSame(0, Booking::query()->count());
    }

    /**
     * LA MINUTE PRESENTE (T127).
     *
     * « Actuellement il est 00h07, je ne peux pas commander une voiture a
     * 00h07, le minimum c'est 01h07. 00h07 ca ne peut pas passer. »
     *
     * Le cas limite exact : ni passe ni futur, l'instant meme. Il tombe du
     * bon cote d'un `>=` mal pose, et aucun test ne le couvrait.
     */
    public function test_la_minute_presente_est_refusee(): void
    {
        $this->reserve(now())
            ->assertStatus(400)
            ->assertJsonPath('error', BookingRefusee::DELAI_PREVENANCE);

        $this->assertSame(0, Booking::query()->count());
    }

    /**
     * Le MEME JOUR a une heure deja passee : « tenir compte heure et jour ».
     *
     * Un garde qui ne compare que les dates laisserait passer ce depart,
     * puisque le jour, lui, est bien aujourd'hui.
     */
    public function test_une_heure_passee_du_jour_meme_est_refusee(): void
    {
        $this->reserve(now()->startOfDay()->addMinutes(7))
            ->assertStatus(400)
            ->assertJsonPath('error', BookingRefusee::DELAI_PREVENANCE);

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_un_depart_dans_dix_minutes_est_refuse(): void
    {
        $this->reserve(now()->addMinutes(10))
            ->assertStatus(400)
            ->assertJsonPath('error', BookingRefusee::DELAI_PREVENANCE);

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_un_depart_dans_trente_et_une_minutes_passe(): void
    {
        $this->reserve(now()->addMinutes(31))->assertSuccessful();

        $this->assertSame(1, Booking::query()->count());
    }

    /**
     * Le delai vient bien des reglages.
     *
     * Sans ce test, un trente ecrit en dur dans le service passerait tous les
     * autres : c'est justement le defaut qu'on evite.
     */
    public function test_allonger_le_delai_repousse_le_refus(): void
    {
        $this->reglages(['lead_time_minutes' => 120]);

        $this->reserve(now()->addMinutes(31))
            ->assertStatus(400)
            ->assertJsonPath('error', BookingRefusee::DELAI_PREVENANCE)
            ->assertJsonPath('message', "Le départ doit être prévu au moins 120 minutes à l'avance.");

        $this->reserve(now()->addMinutes(121))->assertSuccessful();
    }

    /**
     * Le back-office n'est pas bride par ce delai.
     *
     * Une course prise au comptoir part parfois dans dix minutes, et le
     * guichet sait ce qu'il fait. Contrairement aux bornes de duree, qui
     * protegent un prix, celle-ci protege une organisation : le modele ne la
     * tient donc pas.
     */
    public function test_une_ecriture_directe_dans_dix_minutes_reste_possible(): void
    {
        $depart = now()->addMinutes(10);

        $booking = Booking::query()->create([
            'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => $depart,
            'ends_at' => $depart->copy()->addHours(2),
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 2,
            'total' => 20, 'deposit' => 2, 'balance' => 18,
            'currency_id' => $this->vehicle->currency_id,
        ]);

        $this->assertTrue($booking->exists);
    }
}
