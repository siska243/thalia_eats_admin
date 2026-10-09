<?php

namespace Tests\Feature\Rental;

use App\Models\Booking;
use App\Models\Currency;
use App\Models\Town;
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
 * La zone desservie : ce que l'application apprend, et ce qu'elle peut
 * reserver.
 */
class RentalZoneTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * `loadMissingValues: false` est indispensable : une autre suite vide
         * la table `settings`, et sans ce drapeau le test passe seul mais
         * echoue dans la suite complete.
         */
        RentalSettings::fake([
            'deposit_percentage' => 10.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
            'whatsapp_number' => '+243900000000',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Nous ne desservons pas encore cette commune.',
        ], loadMissingValues: false);

        $currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001', 'hourly_rate' => 10,
            'currency_id' => $currency->id,
        ]);

        $this->client = User::factory()->create();
    }

    private function connecte(): void
    {
        Sanctum::actingAs($this->client, ['*']);
    }

    /** @param array<string, mixed> $extra */
    private function reserve(array $extra = []): TestResponse
    {
        return $this->postJson('/api/rental/bookings', array_merge([
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'starts_at' => Carbon::parse('+2 days 10:00')->toDateTimeString(),
            'ends_at' => Carbon::parse('+2 days 15:00')->toDateTimeString(),
            'pickup_location' => 'Gombe',
        ], $extra));
    }

    /**
     * La configuration se lit sans jeton.
     *
     * L'application a besoin des noms commerciaux et de la zone pour dessiner
     * son premier ecran, bien avant qu'on s'y connecte.
     */
    public function test_la_configuration_est_publique(): void
    {
        $desservie = Town::factory()->create(['title' => 'Gombe', 'rental_available' => true]);
        Town::factory()->create(['title' => 'Maluku', 'rental_available' => false]);

        $reponse = $this->getJson('/api/rental/config');

        $reponse->assertSuccessful()
            ->assertJsonPath('data.brand_food', 'Thalia Eats')
            ->assertJsonPath('data.brand_rental', 'Thalia Trans')
            ->assertJsonPath('data.whatsapp_number', '+243900000000')
            ->assertJsonPath('data.out_of_zone_message', 'Nous ne desservons pas encore cette commune.');

        // Seules les communes desservies sortent : la liste sert a savoir ou
        // l'on peut reserver, pas a lister le pays.
        $reponse->assertJsonCount(1, 'data.towns')
            ->assertJsonPath('data.towns.0.id', $desservie->id)
            ->assertJsonPath('data.towns.0.slug', $desservie->slug)
            ->assertJsonPath('data.towns.0.title', 'Gombe');
    }

    /** Hors zone, la reservation est refusee avec le message des reglages. */
    public function test_reserver_hors_zone_est_refuse(): void
    {
        $this->connecte();

        $hors_zone = Town::factory()->create(['rental_available' => false]);

        $this->reserve(['pickup_town_id' => $hors_zone->id])
            ->assertStatus(400)
            ->assertJsonPath('error', 'hors_zone')
            ->assertJsonPath('message', 'Nous ne desservons pas encore cette commune.');

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_reserver_dans_une_commune_desservie_passe(): void
    {
        $this->connecte();

        $desservie = Town::factory()->create(['rental_available' => true]);

        $this->reserve(['pickup_town_id' => $desservie->id])->assertSuccessful();

        $this->assertSame($desservie->id, Booking::query()->value('pickup_town_id'));
    }

    /**
     * Sans commune, la reservation passe.
     *
     * Les applications deja installees n'envoient pas ce champ : les refuser
     * casserait le parc en circulation pour un controle qu'elles ne savent
     * pas encore satisfaire.
     */
    public function test_reserver_sans_commune_passe(): void
    {
        $this->connecte();

        $this->reserve()->assertSuccessful();

        $this->assertNull(Booking::query()->value('pickup_town_id'));
    }
}
