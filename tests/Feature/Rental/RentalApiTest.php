<?php

namespace Tests\Feature\Rental;

use App\Models\Booking;
use App\Models\Chauffeur;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\RentalSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** L'API de location, telle que les applications la consomment. */
class RentalApiTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Vehicle $vehicle;

    private User $client;

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

        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001', 'hourly_rate' => 10,
            'currency_id' => $this->currency->id,
        ]);

        $this->client = User::factory()->create();
    }

    private function connecte(): void
    {
        // L'ability « * » est explicite : `actingAs` ne la donne plus par
        // defaut, et le garde RefuserAgentSansAbility ferme alors la route.
        Sanctum::actingAs($this->client, ['*']);
    }

    private function creneau(string $from = '+2 days 10:00', string $to = '+2 days 15:00'): array
    {
        return [
            'starts_at' => Carbon::parse($from)->toDateTimeString(),
            'ends_at' => Carbon::parse($to)->toDateTimeString(),
        ];
    }

    public function test_le_catalogue_annonce_le_prix_du_creneau(): void
    {
        $this->connecte();

        $reponse = $this->getJson('/api/rental/vehicles?'.http_build_query($this->creneau()));

        $reponse->assertSuccessful();

        // Le prix accompagne le vehicule : sans lui, le client compare des
        // tarifs horaires et decouvre le total une fois son choix fait.
        $reponse->assertJsonPath('data.0.quote.total', 50);
        $reponse->assertJsonPath('data.0.quote.deposit', 5);
        $reponse->assertJsonPath('data.0.quote.balance', 45);
    }

    public function test_le_catalogue_exige_un_creneau(): void
    {
        $this->connecte();

        $this->getJson('/api/rental/vehicles')->assertStatus(422);
    }

    public function test_le_catalogue_cache_un_vehicule_deja_pris(): void
    {
        $this->connecte();

        Booking::query()->create(array_merge($this->creneau(), [
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]));

        $this->getJson('/api/rental/vehicles?'.http_build_query($this->creneau()))
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    public function test_le_client_reserve_et_le_serveur_calcule_le_prix(): void
    {
        $this->connecte();

        $reponse = $this->postJson('/api/rental/bookings', array_merge($this->creneau(), [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'pickup_location' => 'Av. Batetela, Gombe',
        ]));

        $reponse->assertSuccessful();
        $reponse->assertJsonPath('data.total', 50);
        $reponse->assertJsonPath('data.deposit', 5);
        $reponse->assertJsonPath('data.status', Booking::STATUS_PENDING_PAYMENT);
    }

    public function test_un_montant_envoye_par_le_client_est_ignore(): void
    {
        $this->connecte();

        // Le prix ne vient jamais du client : c'est la faille deja corrigee
        // sur les commandes de repas.
        $this->postJson('/api/rental/bookings', array_merge($this->creneau(), [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'pickup_location' => 'Gombe',
            'total' => 1, 'deposit' => 0.1, 'hourly_rate' => 0.01,
        ]))->assertSuccessful()->assertJsonPath('data.total', 50);
    }

    public function test_reserver_un_vehicule_pris_est_refuse(): void
    {
        $this->connecte();

        Booking::query()->create(array_merge($this->creneau(), [
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]));

        $this->postJson('/api/rental/bookings', array_merge($this->creneau(), [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'pickup_location' => 'Gombe',
        ]))->assertStatus(400)->assertJsonPath('error', 'vehicule_indisponible');
    }

    public function test_la_reservation_herite_du_chauffeur_attitre(): void
    {
        $chauffeur = Chauffeur::query()->create([
            'user_id' => User::factory()->create()->id,
            'full_name' => 'Jean Kabila', 'phone' => '+243810000000',
        ]);
        $this->vehicle->update(['default_chauffeur_id' => $chauffeur->id]);

        $this->connecte();

        $this->postJson('/api/rental/bookings', array_merge($this->creneau(), [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'pickup_location' => 'Gombe',
        ]))->assertSuccessful()->assertJsonPath('data.chauffeur.full_name', 'Jean Kabila');
    }

    public function test_on_ne_lit_pas_la_reservation_d_un_autre(): void
    {
        $autre = Booking::query()->create(array_merge($this->creneau(), [
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
        ]));

        $this->connecte();

        // L'uid n'est pas une capacite : il se forge. L'autorisation vient de
        // la clause SQL, pas du fait de connaitre l'identifiant.
        $this->getJson('/api/rental/bookings/'.Cipher::Encrypt($autre->id))
            ->assertStatus(404);
    }

    public function test_le_code_de_prise_en_charge_n_est_montre_qu_a_son_proprietaire(): void
    {
        $booking = Booking::query()->create(array_merge($this->creneau(), [
            'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_CONFIRMED,
            'pickup_code' => '4821',
        ]));

        $this->connecte();

        $this->getJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id))
            ->assertSuccessful()
            ->assertJsonPath('data.pickup_code', '4821');
    }

    public function test_le_client_annule_sa_reservation(): void
    {
        $booking = Booking::query()->create(array_merge($this->creneau(), [
            'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]));

        $this->connecte();

        $this->postJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/cancel', [
            'reason' => 'Changement de programme',
        ])->assertSuccessful();

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->refresh()->status);
    }

    public function test_une_course_commencee_ne_s_annule_plus(): void
    {
        $booking = Booking::query()->create([
            'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 2,
            'total' => 20, 'deposit' => 2, 'balance' => 18,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_IN_PROGRESS,
        ]);

        $this->connecte();

        $this->postJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/cancel')
            ->assertStatus(400)
            ->assertJsonPath('error', 'annulation_impossible');
    }

    /**
     * Le catalogue se consulte sans compte.
     *
     * On regarde les voitures avant de decider d'en louer une : exiger un
     * jeton pour voir les prix perdrait le visiteur avant qu'il ait une
     * raison de s'inscrire.
     */
    public function test_le_catalogue_se_consulte_sans_compte(): void
    {
        $reponse = $this->getJson('/api/rental/vehicles?'.http_build_query($this->creneau()));

        $reponse->assertSuccessful();
        $reponse->assertJsonPath('data.0.quote.total', 50);
    }

    /** La plaque identifie une voiture sur la voie publique : pas au catalogue. */
    public function test_le_catalogue_public_ne_montre_pas_la_plaque(): void
    {
        $this->getJson('/api/rental/vehicles?'.http_build_query($this->creneau()))
            ->assertSuccessful()
            ->assertJsonMissing(['plate_number' => 'KIN-0001']);
    }

    /** Reserver, en revanche, demande un compte. */
    public function test_reserver_exige_une_authentification(): void
    {
        $this->postJson('/api/rental/bookings', array_merge($this->creneau(), [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'pickup_location' => 'Gombe',
        ]))->assertStatus(401);

        $this->getJson('/api/rental/bookings')->assertStatus(401);
    }
}
