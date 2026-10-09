<?php

namespace Tests\Feature\Suivi;

use App\Models\Booking;
use App\Models\Chauffeur;
use App\Models\Commande;
use App\Models\Currency;
use App\Models\DelivreryDriver;
use App\Models\Status;
use App\Models\Town;
use App\Models\TrackOrder;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\RentalSettings;
use App\Settings\SuiviSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Les releves de position : une seule table, un seul service, deux refus.
 *
 * `track_orders` accumulait sans aucune limite — sur la commande 41,
 * dix-neuf releves avec des ecarts de 4, 1 et 2 secondes. La limite vit cote
 * serveur : le parc de telephones installe emet a son rythme et ne se met pas
 * a jour sur commande.
 */
class SuiviPositionsTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private User $client;

    private Chauffeur $chauffeur;

    private User $chauffeurUser;

    /** @param array<string, mixed> $overrides */
    private function reglages(array $overrides = []): void
    {
        SuiviSettings::fake(array_merge([
            'interval_minutes' => 5,
            'stale_minutes' => 15,
            'retention_days' => 90,
        ], $overrides), loadMissingValues: false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reglages();

        /*
         * Les reglages de location sont necessaires ici : le crochet
         * `saving` de `Booking` verifie les bornes de duree. `loadMissingValues:
         * false` impose la liste complete — une autre suite vide la table
         * `settings`, et un reglage oublie passe seul mais tombe dans la
         * suite entiere.
         */
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

        // Aucun appel reel au geocodeur : il est payant, et une suite qui
        // depend d'Internet echoue un jour sans rapport avec le code.
        Http::fake();

        $currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001', 'hourly_rate' => 10,
            'currency_id' => $currency->id,
        ]);

        $this->client = User::factory()->create();

        $this->chauffeurUser = User::factory()->create();
        $this->chauffeur = Chauffeur::query()->create([
            'user_id' => $this->chauffeurUser->id,
            'full_name' => 'Papy Kabila',
            'phone' => '+243900000001',
            'is_active' => true,
        ]);
    }

    private function reservation(string $status = Booking::STATUS_CONFIRMED): Booking
    {
        return Booking::query()->create([
            'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'chauffeur_id' => $this->chauffeur->id,
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(4),
            'pickup_location' => 'Gombe, avenue du Commerce',
            'pickup_lat' => -4.3190, 'pickup_lng' => 15.3080,
            'status' => $status,
            'hourly_rate' => 10, 'duration_hours' => 2,
            'total' => 20, 'deposit' => 2, 'balance' => 18,
            'currency_id' => $this->vehicle->currency_id,
        ]);
    }

    private function emettre(Booking $booking, float $lat = -4.3276, float $lng = 15.3136)
    {
        return $this->postJson(
            '/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/position',
            ['lat' => $lat, 'lng' => $lng],
        );
    }

    // ── La cadence ───────────────────────────────────────────────────────

    public function test_un_second_point_trop_rapproche_n_est_pas_enregistre(): void
    {
        Sanctum::actingAs($this->chauffeurUser, ['*']);
        $booking = $this->reservation();

        $this->emettre($booking)->assertSuccessful()->assertJsonPath('data.enregistre', true);
        $this->emettre($booking, -4.33, 15.32)->assertSuccessful()->assertJsonPath('data.enregistre', false);

        $this->assertSame(1, TrackOrder::query()->count());
    }

    public function test_un_point_passe_une_fois_l_intervalle_ecoule(): void
    {
        Sanctum::actingAs($this->chauffeurUser, ['*']);
        $booking = $this->reservation();

        $this->emettre($booking);

        // On vieillit le releve plutot que de voyager dans le temps : la
        // course, elle, doit rester dans sa fenetre.
        TrackOrder::query()->update(['created_at' => now()->subMinutes(6)]);

        $this->emettre($booking, -4.33, 15.32)->assertJsonPath('data.enregistre', true);

        $this->assertSame(2, TrackOrder::query()->count());
    }

    /** L'intervalle vient des reglages, pas d'un cinq ecrit dans le service. */
    public function test_allonger_l_intervalle_repousse_l_acceptation(): void
    {
        $this->reglages(['interval_minutes' => 30]);

        Sanctum::actingAs($this->chauffeurUser, ['*']);
        $booking = $this->reservation();

        $this->emettre($booking);
        TrackOrder::query()->update(['created_at' => now()->subMinutes(6)]);

        $this->emettre($booking, -4.33, 15.32)->assertJsonPath('data.enregistre', false);
        $this->assertSame(1, TrackOrder::query()->count());
    }

    // ── La fenetre active ────────────────────────────────────────────────

    public function test_une_course_non_payee_n_accepte_aucune_position(): void
    {
        Sanctum::actingAs($this->chauffeurUser, ['*']);
        $booking = $this->reservation(Booking::STATUS_PENDING_PAYMENT);

        $this->emettre($booking)
            ->assertStatus(400)
            ->assertJsonPath('error', 'hors_course');

        $this->assertSame(0, TrackOrder::query()->count());
    }

    public function test_une_course_terminee_n_accepte_plus_aucune_position(): void
    {
        Sanctum::actingAs($this->chauffeurUser, ['*']);
        $booking = $this->reservation(Booking::STATUS_COMPLETED);

        $this->emettre($booking)->assertStatus(400);

        $this->assertSame(0, TrackOrder::query()->count());
    }

    // ── Qui a le droit d'emettre ─────────────────────────────────────────

    public function test_un_autre_chauffeur_ne_peut_pas_emettre(): void
    {
        $intrus = User::factory()->create();
        Chauffeur::query()->create([
            'user_id' => $intrus->id,
            'full_name' => 'Intrus',
            'phone' => '+243900000002',
            'is_active' => true,
        ]);

        Sanctum::actingAs($intrus, ['*']);
        $booking = $this->reservation();

        $this->emettre($booking)->assertStatus(404);

        $this->assertSame(0, TrackOrder::query()->count());
    }

    public function test_le_client_ne_peut_pas_emettre_a_la_place_du_chauffeur(): void
    {
        Sanctum::actingAs($this->client, ['*']);
        $booking = $this->reservation();

        $this->emettre($booking)->assertStatus(404);
    }

    // ── La lecture par le client ─────────────────────────────────────────

    public function test_le_suivi_respecte_le_contrat(): void
    {
        Sanctum::actingAs($this->chauffeurUser, ['*']);
        $booking = $this->reservation();
        $this->emettre($booking);

        Sanctum::actingAs($this->client, ['*']);

        $reponse = $this->getJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/suivi');

        $reponse->assertSuccessful()
            ->assertJsonPath('data.chauffeur.lat', -4.3276)
            ->assertJsonPath('data.chauffeur.lng', 15.3136)
            ->assertJsonPath('data.pickup.lat', -4.319)
            ->assertJsonPath('data.pickup.lng', 15.308)
            ->assertJsonPath('data.pickup.label', 'Gombe, avenue du Commerce');

        // La forme exacte, celle sur laquelle le mobile est deja ecrit.
        $this->assertSame(
            ['chauffeur', 'pickup'],
            array_keys($reponse->json('data')),
        );
        $this->assertSame(
            ['lat', 'lng', 'updated_at'],
            array_keys($reponse->json('data.chauffeur')),
        );
        $this->assertSame(
            ['lat', 'lng', 'label'],
            array_keys($reponse->json('data.pickup')),
        );
    }

    public function test_sans_position_le_chauffeur_est_nul(): void
    {
        Sanctum::actingAs($this->client, ['*']);
        $booking = $this->reservation();

        $this->getJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/suivi')
            ->assertSuccessful()
            ->assertJsonPath('data.chauffeur', null)
            ->assertJsonPath('data.pickup.label', 'Gombe, avenue du Commerce');
    }

    public function test_une_position_trop_vieille_ne_s_affiche_pas(): void
    {
        Sanctum::actingAs($this->chauffeurUser, ['*']);
        $booking = $this->reservation();
        $this->emettre($booking);

        TrackOrder::query()->update(['created_at' => now()->subMinutes(16)]);

        Sanctum::actingAs($this->client, ['*']);

        $this->getJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/suivi')
            ->assertSuccessful()
            ->assertJsonPath('data.chauffeur', null);
    }

    public function test_un_autre_client_ne_lit_pas_le_suivi(): void
    {
        $booking = $this->reservation();

        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/suivi')
            ->assertStatus(404);
    }

    // ── Les repas : meme tuyauterie, meme limite ─────────────────────────

    private function commandeEnLivraison(): array
    {
        Status::query()->firstOrCreate(['id' => 2], ['title' => 'En cours', 'slug' => 'en-cours']);

        $livreurUser = User::factory()->create();
        $livreur = DelivreryDriver::query()->create([
            'user_id' => $livreurUser->id,
            'id_card' => 'CARTE-'.$livreurUser->id,
            'is_active' => true,
        ]);

        $commande = Commande::query()->create([
            'user_id' => $this->client->id,
            'status_id' => 2,
            'town_id' => Town::factory()->create()->id,
            'delivrery_driver_id' => $livreur->id,
            'refernce' => 'CMD-'.uniqid(),
            'global_price' => 10,
            'adresse_delivery' => 'Avenue du Commerce 12',
            'accepted_at' => now(),
        ]);

        return [$commande, $livreurUser];
    }

    private function emettreRepas(Commande $commande, array $location)
    {
        return $this->postJson('/api/user/commande/update-track', [
            'uid' => Cipher::Encrypt($commande->id),
            'location' => $location,
        ]);
    }

    public function test_la_limite_vaut_aussi_pour_les_livreurs_de_repas(): void
    {
        [$commande, $livreurUser] = $this->commandeEnLivraison();

        Sanctum::actingAs($livreurUser, ['*']);

        $this->emettreRepas($commande, ['lat' => -4.32, 'lng' => 15.31])->assertSuccessful();
        $this->emettreRepas($commande, ['lat' => -4.33, 'lng' => 15.32])->assertSuccessful();

        // Deux appels, un seul releve : c'est tout l'objet du ticket.
        $this->assertSame(1, TrackOrder::query()->count());
    }

    /**
     * La reponse de `get-track/{uid}` n'a pas change de forme.
     *
     * L'application installee la lit telle quelle. Le releve vit desormais
     * dans une table polymorphe, mais `commande_id` est toujours la, deduite
     * du lien, et la lecture passe par le meme chemin.
     */
    public function test_la_lecture_de_la_carte_repas_est_inchangee(): void
    {
        [$commande, $livreurUser] = $this->commandeEnLivraison();

        Sanctum::actingAs($livreurUser, ['*']);
        $this->emettreRepas($commande, ['lat' => -4.32, 'lng' => 15.31]);

        Sanctum::actingAs($this->client, ['*']);

        $reponse = $this->getJson('/api/user/commande/get-track/'.Cipher::Encrypt($commande->id));

        $reponse->assertSuccessful();

        $this->assertSame(
            ['id', 'commande_id', 'location_delivery', 'location_customer', 'delivered', 'created_at', 'updated_at'],
            array_keys($reponse->json()),
        );
        $this->assertSame($commande->id, $reponse->json('commande_id'));
        $this->assertSame(['lat' => -4.32, 'lng' => 15.31], $reponse->json('location_delivery'));
    }

    // ── La purge ─────────────────────────────────────────────────────────

    public function test_la_purge_supprime_les_vieux_releves_et_garde_les_recents(): void
    {
        Sanctum::actingAs($this->chauffeurUser, ['*']);
        $booking = $this->reservation();

        $this->emettre($booking);
        $recent = TrackOrder::query()->latest('id')->first();

        TrackOrder::query()->create([
            'suivable_type' => Booking::class,
            'suivable_id' => $booking->id,
            'location_delivery' => ['lat' => -4.4, 'lng' => 15.4],
            'created_at' => now()->subDays(91),
        ]);

        $this->artisan('suivi:purger')->assertSuccessful();

        $this->assertSame(1, TrackOrder::query()->count());
        $this->assertNotNull(TrackOrder::query()->whereKey($recent->id)->first());
    }

    public function test_la_simulation_ne_supprime_rien(): void
    {
        $booking = $this->reservation();

        TrackOrder::query()->create([
            'suivable_type' => Booking::class,
            'suivable_id' => $booking->id,
            'location_delivery' => ['lat' => -4.4, 'lng' => 15.4],
            'created_at' => now()->subDays(91),
        ]);

        $this->artisan('suivi:purger --simuler')->assertSuccessful();

        $this->assertSame(1, TrackOrder::query()->count());
    }
}
