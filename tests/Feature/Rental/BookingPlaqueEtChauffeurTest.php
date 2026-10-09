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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La plaque et le chauffeur ne sortent que sur une course payee et pas finie.
 *
 * Les masquer dans l'application n'aurait rien masque : la reponse reste
 * lisible par qui detient le jeton. La plaque designe un vehicule precis sur
 * la voie publique, le chauffeur est une personne nommee avec sa photo et son
 * telephone. La regle est donc du cote serveur, comme celle de `pickup_code`.
 *
 * Elle s'enonce « la course est payee et pas encore finie » : `confirmed` et
 * `in_progress` oui, tout le reste non. Une course confirmee compte, meme si
 * elle n'a pas commence — le client a regle son acompte, il a le droit de
 * savoir quelle voiture vient le chercher.
 */
class BookingPlaqueEtChauffeurTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Vehicle $vehicle;

    private Currency $currency;

    private Chauffeur $chauffeur;

    protected function setUp(): void
    {
        parent::setUp();

        RentalSettings::fake([
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
        ], loadMissingValues: false);

        $this->currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001', 'hourly_rate' => 10,
            'currency_id' => $this->currency->id, 'is_active' => true,
        ]);

        $this->chauffeur = Chauffeur::query()->create([
            'user_id' => User::factory()->create()->id,
            'full_name' => 'Jean Mukendi',
            'phone' => '+243810000000',
        ]);

        $this->client = User::factory()->create();
        Sanctum::actingAs($this->client, ['*']);
    }

    private function reservation(string $statut): Booking
    {
        return Booking::query()->create([
            'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'chauffeur_id' => $this->chauffeur->id,
            'currency_id' => $this->currency->id,
            'status' => $statut,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(5),
            'pickup_location' => 'Avenue du Commerce 12',
            'hourly_rate' => 10,
            'duration_hours' => 5,
            'total' => 50,
            'deposit' => 5,
            'balance' => 45,
        ]);
    }

    private function lire(Booking $booking): TestResponse
    {
        return $this->getJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id));
    }

    public function test_rien_n_est_rendu_sur_une_reservation_en_attente_de_paiement(): void
    {
        $reponse = $this->lire($this->reservation(Booking::STATUS_PENDING_PAYMENT));

        $reponse->assertSuccessful();
        $reponse->assertJsonPath('data.plate_number', null);
        $reponse->assertJsonPath('data.chauffeur', null);
    }

    public function test_tout_est_rendu_sur_une_reservation_payee_pas_encore_commencee(): void
    {
        $reponse = $this->lire($this->reservation(Booking::STATUS_CONFIRMED));

        $reponse->assertSuccessful();
        $reponse->assertJsonPath('data.plate_number', 'KIN-0001');
        $reponse->assertJsonPath('data.chauffeur.full_name', 'Jean Mukendi');
        $reponse->assertJsonPath('data.chauffeur.phone', '+243810000000');
    }

    public function test_tout_est_rendu_pendant_la_course(): void
    {
        $reponse = $this->lire($this->reservation(Booking::STATUS_IN_PROGRESS));

        $reponse->assertSuccessful();
        $reponse->assertJsonPath('data.plate_number', 'KIN-0001');
        $reponse->assertJsonPath('data.chauffeur.full_name', 'Jean Mukendi');
    }

    public function test_plus_rien_n_est_rendu_une_fois_la_course_terminee(): void
    {
        $reponse = $this->lire($this->reservation(Booking::STATUS_COMPLETED));

        $reponse->assertSuccessful();
        $reponse->assertJsonPath('data.plate_number', null);
        $reponse->assertJsonPath('data.chauffeur', null);
    }

    public function test_rien_n_est_rendu_sur_une_reservation_annulee(): void
    {
        $reponse = $this->lire($this->reservation(Booking::STATUS_CANCELLED));

        $reponse->assertSuccessful();
        $reponse->assertJsonPath('data.plate_number', null);
        $reponse->assertJsonPath('data.chauffeur', null);
    }

    public function test_la_liste_obeit_a_la_meme_regle(): void
    {
        // Le controle vit dans la ressource, pas dans le controleur : la
        // liste ne peut donc pas divergier de la fiche.
        $this->reservation(Booking::STATUS_PENDING_PAYMENT);

        $reponse = $this->getJson('/api/rental/bookings');

        $reponse->assertSuccessful();
        $reponse->assertJsonPath('data.0.plate_number', null);
        $reponse->assertJsonPath('data.0.chauffeur', null);
    }
}
