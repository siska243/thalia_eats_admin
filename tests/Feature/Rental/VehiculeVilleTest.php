<?php

namespace Tests\Feature\Rental;

use App\Models\City;
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
 * Un vehicule appartient a une ville.
 *
 * Le proprietaire, le 15 octobre : « il y a pas des limites des kilometres
 * mais il y'a des limites par rapport a la ville (province) ». Et : « ils
 * peuvent voir les vehicules disponibles qui se trouvent dans tous les pays
 * mais ils peuvent commander que pour la course dans le pays d'origine de la
 * voiture ».
 *
 * Le catalogue reste donc MONDIAL, et c'est la reservation qui refuse. Un
 * vehicule sans ville — tout le parc existant — reste reservable partout :
 * aucune regression le jour de la migration.
 */
class VehiculeVilleTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Currency $currency;

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
            'hours_min' => 1,
            'hours_max' => 23,
            'days_min' => 1,
            'days_max' => 30,
            'lead_time_minutes' => 30,
            'whatsapp_number' => '+243900000000',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Nous ne desservons pas encore cette commune.',
        ], loadMissingValues: false);

        $this->currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->client = User::factory()->create();
        Sanctum::actingAs($this->client, ['*']);
    }

    private function vehicule(?City $ville = null): Vehicle
    {
        return Vehicle::query()->create([
            'brand' => 'Toyota',
            'model' => 'Land Cruiser',
            'plate_number' => 'KIN-'.uniqid(),
            'hourly_rate' => 10,
            'currency_id' => $this->currency->id,
            'city_id' => $ville?->id,
        ]);
    }

    private function reserve(Vehicle $vehicle, Town $depart): TestResponse
    {
        return $this->postJson('/api/rental/bookings', [
            'vehicle' => Cipher::Encrypt($vehicle->id),
            'starts_at' => Carbon::parse('+2 days 10:00')->toDateTimeString(),
            'ends_at' => Carbon::parse('+2 days 15:00')->toDateTimeString(),
            'pickup_location' => 'Gombe',
            'pickup_town_id' => $depart->id,
        ]);
    }

    /** Le cas de non-regression : tout le parc existant n'a pas de ville. */
    public function test_un_vehicule_sans_ville_se_reserve_partout(): void
    {
        $ville = City::factory()->create(['title' => 'Kinshasa']);
        $commune = Town::factory()->create(['city_id' => $ville->id, 'rental_available' => true]);

        $this->reserve($this->vehicule(), $commune)->assertSuccessful();
    }

    public function test_un_vehicule_se_reserve_dans_sa_ville(): void
    {
        $ville = City::factory()->create(['title' => 'Kinshasa']);
        $commune = Town::factory()->create(['city_id' => $ville->id, 'rental_available' => true]);

        $this->reserve($this->vehicule($ville), $commune)->assertSuccessful();
    }

    /**
     * Le refus nomme la ville du vehicule, et porte son propre code.
     *
     * `hors_ville` est distinct de `hors_zone` : « cette commune n'est pas
     * desservie » envoie le client vers WhatsApp, « ce vehicule n'est pas
     * d'ici » l'envoie vers un autre vehicule. Deux suites differentes.
     */
    public function test_un_vehicule_d_une_autre_ville_est_refuse(): void
    {
        $kinshasa = City::factory()->create(['title' => 'Kinshasa']);
        $lubumbashi = City::factory()->create(['title' => 'Lubumbashi']);
        $commune = Town::factory()->create(['city_id' => $kinshasa->id, 'rental_available' => true]);

        $this->reserve($this->vehicule($lubumbashi), $commune)
            ->assertStatus(400)
            ->assertJsonPath('error', 'hors_ville')
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Lubumbashi'));
    }

    /**
     * La commune fermee prime.
     *
     * Une commune peut etre fermee meme dans la bonne ville, et c'est ce
     * refus-la qu'il faut montrer : il envoie vers WhatsApp.
     */
    public function test_une_commune_fermee_prime_sur_la_ville(): void
    {
        $ville = City::factory()->create(['title' => 'Kinshasa']);
        $commune = Town::factory()->create(['city_id' => $ville->id, 'rental_available' => false]);

        $this->reserve($this->vehicule($ville), $commune)
            ->assertStatus(400)
            ->assertJsonPath('error', 'hors_zone');
    }

    // --- Le catalogue reste mondial -----------------------------------------

    /** On VOIT les vehicules des autres villes ; on ne peut pas les reserver. */
    public function test_le_catalogue_rend_les_vehicules_de_toutes_les_villes(): void
    {
        $kinshasa = City::factory()->create(['title' => 'Kinshasa']);
        $lubumbashi = City::factory()->create(['title' => 'Lubumbashi']);
        $this->vehicule($kinshasa);
        $this->vehicule($lubumbashi);

        $this->getJson('/api/rental/vehicles')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data');
    }

    /** Avec une commune de depart, chaque vehicule dit s'il est reservable. */
    public function test_le_catalogue_dit_quels_vehicules_sont_reservables(): void
    {
        $kinshasa = City::factory()->create(['title' => 'Kinshasa']);
        $lubumbashi = City::factory()->create(['title' => 'Lubumbashi']);
        $commune = Town::factory()->create(['city_id' => $kinshasa->id, 'rental_available' => true]);

        $kinois = $this->vehicule($kinshasa);
        $this->vehicule($lubumbashi);

        $reponse = $this->getJson('/api/rental/vehicles?pickup_town_id='.$commune->id)
            ->assertSuccessful();

        $reservables = collect($reponse->json('data'))
            ->mapWithKeys(fn ($v) => [$v['city']['title'] ?? '—' => $v['reservable']]);

        $this->assertTrue($reservables['Kinshasa']);
        $this->assertFalse($reservables['Lubumbashi']);
        $this->assertSame('Kinshasa', $kinois->fresh()->city->title);
    }

    /** Sans commune de depart, rien ne change : tout est reservable. */
    public function test_sans_commune_de_depart_tout_est_reservable(): void
    {
        $this->vehicule(City::factory()->create(['title' => 'Lubumbashi']));

        $this->getJson('/api/rental/vehicles')
            ->assertSuccessful()
            ->assertJsonPath('data.0.reservable', true);
    }
}
