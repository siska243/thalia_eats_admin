<?php

namespace Tests\Feature\Rental;

use App\Exceptions\BookingRefusee;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Rental\BookingCreator;
use App\Services\Rental\BookingWorkflow;
use App\Services\Rental\DepositPayment;
use App\Settings\RentalSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Une seule reservation non reglee a la fois, et elle perime.
 *
 * Le proprietaire : « quand on a deja une course non payee on peut pas passer
 * une autre course, il faut d'abord qu'on paye ou on annule, mais
 * l'annulation automatique apres 15 minutes ».
 *
 * Les deux moities comptent autant l'une que l'autre. Le blocage seul
 * enfermerait le client qui a change d'avis : il n'a rien a payer, rien a
 * annuler qui l'interesse, et il ne peut plus reserver. L'expiration le
 * libere, et elle est calculee a la lecture, pas seulement ecrite par la
 * tache planifiee — sinon un planificateur arrete rendrait la regle fausse
 * sans que personne ne le voie.
 */
class ReservationUniqueEtExpirationTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private User $client;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reglages();

        $this->currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001', 'hourly_rate' => 10, 'is_active' => true,
            'currency_id' => $this->currency->id,
        ]);

        $this->client = User::factory()->create();
    }

    /**
     * `loadMissingValues: false` impose la liste complete : une autre suite
     * vide la table `settings`, et une propriete oubliee ici ferait tomber la
     * classe dans la suite entiere alors qu'elle passe seule.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function reglages(array $overrides = []): void
    {
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

    private function reserver(string $from = '+2 days 10:00', string $to = '+2 days 15:00'): Booking
    {
        return app(BookingCreator::class)->create(
            $this->client,
            $this->vehicle,
            Carbon::parse($from),
            Carbon::parse($to),
            ['pickup_location' => 'Avenue du Commerce 12'],
        );
    }

    public function test_une_seconde_reservation_est_refusee_tant_que_la_premiere_est_impayee(): void
    {
        $premiere = $this->reserver();

        try {
            $this->reserver('+3 days 10:00', '+3 days 15:00');
            $this->fail('La seconde reservation aurait du etre refusee.');
        } catch (BookingRefusee $e) {
            $this->assertSame(BookingRefusee::RESERVATION_NON_REGLEE, $e->raison);
            // Le message nomme la reservation qui bloque : sans sa reference,
            // le client cherche laquelle dans une liste.
            $this->assertStringContainsString($premiere->reference, (string) $e->messageClient);
        }

        $this->assertSame(1, Booking::query()->count());
    }

    public function test_une_seconde_reservation_passe_quand_la_premiere_a_expire(): void
    {
        $premiere = $this->reserver();

        // L'echeance est depassee, mais aucune tache n'est passee : la regle
        // doit etre juste sans elle.
        $premiere->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();

        $seconde = $this->reserver('+3 days 10:00', '+3 days 15:00');

        $this->assertNotSame($premiere->id, $seconde->id);
        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $seconde->status);

        // Et la premiere est effectivement eteinte en base, pas seulement
        // ignoree : sinon elle resterait « en attente de paiement » dans
        // l'administration et dans les ecrans du client.
        $this->assertSame(Booking::STATUS_EXPIRED, $premiere->refresh()->status);
    }

    public function test_apres_paiement_une_nouvelle_reservation_passe(): void
    {
        $premiere = $this->reserver();
        $premiere->update(['status' => Booking::STATUS_CONFIRMED, 'confirmed_at' => now()]);

        $seconde = $this->reserver('+5 days 10:00', '+5 days 15:00');

        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $seconde->status);
        $this->assertSame(Booking::STATUS_CONFIRMED, $premiere->refresh()->status);
    }

    public function test_apres_annulation_une_nouvelle_reservation_passe(): void
    {
        $premiere = $this->reserver();
        app(BookingWorkflow::class)->cancel($premiere, 'changement de programme');

        $seconde = $this->reserver('+5 days 10:00', '+5 days 15:00');

        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $seconde->status);
    }

    public function test_une_reservation_perimee_se_lit_comme_expiree_sans_tache(): void
    {
        $booking = $this->reserver();
        $booking->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();

        $this->assertTrue($booking->estExpiree());
        $this->assertSame(Booking::STATUS_EXPIRED, $booking->statutEffectif());
        // La base, elle, n'a pas encore bouge : c'est bien la lecture qui
        // tranche.
        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $booking->getRawOriginal('status'));
        $this->assertFalse($booking->isCancellable());
    }

    public function test_le_delai_vient_de_la_configuration(): void
    {
        config()->set('expiration.minutes', 45);

        $booking = $this->reserver();

        $this->assertEqualsWithDelta(
            45,
            now()->diffInMinutes($booking->expires_at),
            1,
            "L'echeance doit suivre config('expiration.minutes').",
        );
    }

    public function test_l_api_refuse_la_seconde_reservation_avec_un_code_lisible(): void
    {
        Sanctum::actingAs($this->client, ['*']);

        $premiere = $this->reserver();

        $reponse = $this->postJson('/api/rental/bookings', [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'starts_at' => Carbon::parse('+3 days 10:00')->toDateTimeString(),
            'ends_at' => Carbon::parse('+3 days 15:00')->toDateTimeString(),
            'pickup_location' => 'Avenue du Commerce 12',
        ]);

        $reponse->assertStatus(400);
        $reponse->assertJsonPath('error', BookingRefusee::RESERVATION_NON_REGLEE);
        $reponse->assertJsonPath('title', 'Oups');
        $this->assertStringContainsString($premiere->reference, $reponse->json('message'));
    }

    /**
     * Une reservation posee directement en base.
     *
     * `BookingCreator` refuse desormais d'en creer une seconde pour le meme
     * client : pour eprouver la tache planifiee il faut plusieurs lignes, donc
     * on les ecrit sans passer par lui.
     */
    private function reservationBrute(string $statut, ?Carbon $echeance): Booking
    {
        $booking = Booking::query()->create([
            'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
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

        $booking->forceFill(['expires_at' => $echeance])->saveQuietly();

        return $booking;
    }

    public function test_la_tache_planifiee_marque_les_bonnes_reservations(): void
    {
        $perimee = $this->reservationBrute(Booking::STATUS_PENDING_PAYMENT, now()->subMinute());
        $recente = $this->reservationBrute(Booking::STATUS_PENDING_PAYMENT, now()->addMinutes(10));

        // Deja tranchee : la tache n'y touche pas, meme avec une echeance
        // ancienne restee en base.
        $confirmee = $this->reservationBrute(Booking::STATUS_CONFIRMED, now()->subDay());

        $this->artisan('expirations:traiter')->assertSuccessful();

        $this->assertSame(Booking::STATUS_EXPIRED, $perimee->refresh()->status);
        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $recente->refresh()->status);
        $this->assertSame(Booking::STATUS_CONFIRMED, $confirmee->refresh()->status);
    }

    public function test_une_reservation_sans_echeance_ne_disparait_pas(): void
    {
        // Les lignes heritees n'en portent pas : « pas d'echeance » se lit
        // « ne jamais expirer ». C'est la migration qui les traite.
        $ancienne = $this->reserver();
        $ancienne->forceFill(['expires_at' => null])->saveQuietly();

        $this->artisan('expirations:traiter')->assertSuccessful();

        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $ancienne->refresh()->status);
        $this->assertFalse($ancienne->estExpiree());
    }

    public function test_l_api_rend_l_echeance_pour_le_decompte(): void
    {
        Sanctum::actingAs($this->client, ['*']);

        $booking = $this->reserver();

        $reponse = $this->getJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id))
            ->assertSuccessful();

        $reponse->assertJsonPath('data.status', Booking::STATUS_PENDING_PAYMENT);
        // Meme format ISO que les autres dates de la ressource : c'est ce que
        // les deux applications savent deja lire.
        $this->assertSame(
            $booking->expires_at->toJSON(),
            $reponse->json('data.expires_at'),
        );
    }

    public function test_l_echeance_disparait_des_que_la_reservation_est_depassee(): void
    {
        Sanctum::actingAs($this->client, ['*']);

        $booking = $this->reserver();
        $booking->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();

        $reponse = $this->getJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id))
            ->assertSuccessful();

        // Pas de decompte negatif a cote d'un statut qui dirait le contraire :
        // la ressource dit « expired », et plus d'echeance.
        $reponse->assertJsonPath('data.status', Booking::STATUS_EXPIRED);
        $reponse->assertJsonPath('data.expires_at', null);
    }

    public function test_l_echeance_ne_sort_pas_sur_une_reservation_deja_tranchee(): void
    {
        Sanctum::actingAs($this->client, ['*']);

        // La colonne garde en base la valeur posee a la creation : sans ce
        // garde, l'application afficherait un decompte sur une course payee.
        foreach ([Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED, Booking::STATUS_CANCELLED] as $statut) {
            $booking = $this->reservationBrute($statut, now()->addMinutes(10));

            $this->getJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id))
                ->assertSuccessful()
                ->assertJsonPath('data.expires_at', null);
        }
    }

    public function test_l_acompte_est_refuse_sur_une_reservation_perimee(): void
    {
        $booking = $this->reserver();
        $booking->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();

        $resultat = app(DepositPayment::class)
            ->initier($booking, 'mobile', '+243810000000');

        // Debiter un client pour une reservation que la creation suivante va
        // eteindre, c'est encaisser pour rien.
        $this->assertFalse($resultat['ok']);
        $this->assertSame('reservation_expiree', $resultat['raison']);
    }
}
