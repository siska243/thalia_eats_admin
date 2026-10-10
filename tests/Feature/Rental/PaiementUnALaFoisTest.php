<?php

namespace Tests\Feature\Rental;

use App\Exceptions\BookingRefusee;
use App\Models\Booking;
use App\Models\ConfigurationPayement;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Rental\BookingWorkflow;
use App\Services\Rental\DepositPayment;
use App\Settings\RentalSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Un seul client paie un vehicule a la fois.
 *
 * Le proprietaire : « 2 ne peuvent pas payer au meme moment, s'il y a deja
 * quelqu'un sur la voiture il faut le notifier, il faut bloquer le paiement de
 * l'autre ».
 *
 * Avant, une reservation non reglee ne retenait rien : deux clients pouvaient
 * partir payer le meme vehicule sur le meme creneau, les deux etaient debites,
 * et le perdant attendait un remboursement a la main. On refuse maintenant le
 * second avant le debit.
 *
 * La retenue dure ce que dure l'echeance deja en place (`expires_at`, reglee
 * dans l'administration) : un seul compte a rebours dans le produit.
 */
class PaiementUnALaFoisTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Vehicle $vehicle;

    private User $premier;

    private User $second;

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
            'lead_time_minutes' => 30,
            'whatsapp_number' => '',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Nous ne desservons pas encore cette commune.',
        ], loadMissingValues: false);

        ConfigurationPayement::query()->create([
            'token' => 'jeton', 'token_key' => 'marchand', 'active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->vehicle = $this->vehicule('KIN-0001');
        $this->premier = User::factory()->create();
        $this->second = User::factory()->create();
    }

    private function vehicule(string $plaque): Vehicle
    {
        return Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => $plaque, 'hourly_rate' => 10, 'is_active' => true,
            'currency_id' => $this->currency->id,
        ]);
    }

    private function reservation(
        User $client,
        ?Vehicle $vehicle = null,
        string $from = '+2 days 10:00',
        string $to = '+2 days 15:00',
    ): Booking {
        return Booking::query()->create([
            'user_id' => $client->id,
            'vehicle_id' => ($vehicle ?? $this->vehicle)->id,
            'starts_at' => Carbon::parse($from),
            'ends_at' => Carbon::parse($to),
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
        ]);
    }

    /** La passerelle accepte l'appel et rend un numero de transaction. */
    private function passerelleAccepte(): void
    {
        Http::fake([
            '*/paymentService' => Http::response(['code' => '0', 'orderNumber' => 'FX-1'], 200),
        ]);
    }

    /**
     * @return array{ok: bool, raison?: string, message?: string, url?: string, orderNumber?: string}
     */
    private function payer(Booking $booking): array
    {
        return app(DepositPayment::class)->initier($booking, 'mobile', '+243810000000');
    }

    public function test_deux_paiements_simultanes_un_seul_passe(): void
    {
        $this->passerelleAccepte();

        $premiere = $this->reservation($this->premier);
        $seconde = $this->reservation($this->second);

        $this->assertTrue($this->payer($premiere)['ok']);

        $refus = $this->payer($seconde);

        $this->assertFalse($refus['ok']);
        $this->assertSame(BookingRefusee::PAIEMENT_EN_COURS, $refus['raison']);

        // Et le second n'a pas ete debite : un seul appel a la passerelle.
        Http::assertSentCount(1);
    }

    public function test_le_meme_client_peut_relancer_son_propre_paiement(): void
    {
        $this->passerelleAccepte();

        $booking = $this->reservation($this->premier);

        $this->assertTrue($this->payer($booking)['ok']);
        // Combine eteint, premier essai abandonne : ce n'est pas un conflit,
        // c'est lui qui tient deja le creneau.
        $this->assertTrue($this->payer($booking)['ok']);
    }

    public function test_le_creneau_se_libere_quand_la_premiere_expire(): void
    {
        $this->passerelleAccepte();

        $premiere = $this->reservation($this->premier);
        $seconde = $this->reservation($this->second);

        $this->payer($premiere);

        // L'echeance est depassee et aucune tache n'est passee : la regle doit
        // etre juste sans elle, comme partout ailleurs dans le module.
        $premiere->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();

        $this->assertTrue($this->payer($seconde)['ok']);
    }

    public function test_une_fois_la_premiere_payee_la_seconde_reste_refusee(): void
    {
        $this->passerelleAccepte();

        $premiere = $this->reservation($this->premier);
        $seconde = $this->reservation($this->second);

        $this->payer($premiere);
        $premiere->update(['status' => Booking::STATUS_CONFIRMED, 'confirmed_at' => now()]);

        // Meme echeance depassee : une course payee ne rend pas le creneau.
        $premiere->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();

        $refus = $this->payer($seconde);

        $this->assertFalse($refus['ok']);
        $this->assertSame(BookingRefusee::PAIEMENT_EN_COURS, $refus['raison']);
    }

    public function test_une_fois_la_premiere_annulee_la_seconde_passe(): void
    {
        $this->passerelleAccepte();

        $premiere = $this->reservation($this->premier);
        $seconde = $this->reservation($this->second);

        $this->payer($premiere);
        app(BookingWorkflow::class)->cancel($premiere, 'changement de programme');

        $this->assertTrue($this->payer($seconde)['ok']);
    }

    public function test_deux_creneaux_qui_ne_se_chevauchent_pas_ne_se_bloquent_pas(): void
    {
        $this->passerelleAccepte();

        $premiere = $this->reservation($this->premier, null, '+2 days 10:00', '+2 days 15:00');
        $seconde = $this->reservation($this->second, null, '+3 days 10:00', '+3 days 15:00');

        $this->assertTrue($this->payer($premiere)['ok']);
        // Le verrou protege une decision, il ne serialise pas le parc : meme
        // vehicule, autre journee, aucun conflit.
        $this->assertTrue($this->payer($seconde)['ok']);
    }

    public function test_deux_vehicules_differents_ne_se_bloquent_pas(): void
    {
        $this->passerelleAccepte();

        $premiere = $this->reservation($this->premier);
        $seconde = $this->reservation($this->second, $this->vehicule('KIN-0002'));

        $this->assertTrue($this->payer($premiere)['ok']);
        $this->assertTrue($this->payer($seconde)['ok']);
    }

    public function test_un_paiement_refuse_par_la_passerelle_rend_le_creneau(): void
    {
        // Un seul `fake` : les stubs s'empilent, le premier qui correspond
        // repond. La sequence donne le refus puis l'acceptation.
        Http::fake([
            '*/paymentService' => Http::sequence()
                ->push(['code' => '1', 'message' => 'Solde insuffisant'])
                ->push(['code' => '0', 'orderNumber' => 'FX-1']),
        ]);

        $premiere = $this->reservation($this->premier);
        $seconde = $this->reservation($this->second);

        $this->assertFalse($this->payer($premiere)['ok']);
        $this->assertNull($premiere->refresh()->deposit_started_at);

        // Sans la liberation, ce vehicule serait bloque un quart d'heure
        // parce qu'un client a mal tape son code.
        $this->assertTrue($this->payer($seconde)['ok']);
    }

    public function test_une_exception_de_la_passerelle_rend_le_creneau(): void
    {
        Http::fake(fn () => throw new RuntimeException('reseau coupe'));

        $booking = $this->reservation($this->premier);

        try {
            $this->payer($booking);
            $this->fail("L'exception de la passerelle aurait du remonter.");
        } catch (RuntimeException) {
            // Attendu : le controleur n'a rien a inventer d'une panne reseau.
        }

        $this->assertNull(
            $booking->refresh()->deposit_started_at,
            'Un vehicule ne doit pas rester bloque parce que la passerelle est tombee.',
        );
    }

    public function test_le_catalogue_cache_un_vehicule_en_cours_de_paiement(): void
    {
        $this->passerelleAccepte();

        $this->payer($this->reservation($this->premier));

        Sanctum::actingAs($this->second, ['*']);

        $this->getJson('/api/rental/vehicles?'.http_build_query([
            'starts_at' => Carbon::parse('+2 days 11:00')->toDateTimeString(),
            'ends_at' => Carbon::parse('+2 days 13:00')->toDateTimeString(),
        ]))->assertSuccessful()->assertJsonCount(0, 'data');
    }

    public function test_l_api_refuse_la_reservation_du_second_avec_un_code_lisible(): void
    {
        $this->passerelleAccepte();

        $this->payer($this->reservation($this->premier));

        Sanctum::actingAs($this->second, ['*']);

        $reponse = $this->postJson('/api/rental/bookings', [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'starts_at' => Carbon::parse('+2 days 11:00')->toDateTimeString(),
            'ends_at' => Carbon::parse('+2 days 13:00')->toDateTimeString(),
            'pickup_location' => 'Avenue du Commerce 12',
        ]);

        $reponse->assertStatus(400);
        $reponse->assertJsonPath('error', BookingRefusee::PAIEMENT_EN_COURS);
        $reponse->assertJsonPath(
            'message',
            'Ce véhicule est en cours de réservation par un autre client. '
            .'Réessayez dans quelques minutes, ou choisissez un autre véhicule.',
        );
    }

    public function test_l_api_refuse_l_acompte_du_second_avec_un_code_lisible(): void
    {
        $this->passerelleAccepte();

        $this->payer($this->reservation($this->premier));

        $seconde = $this->reservation($this->second);
        Sanctum::actingAs($this->second, ['*']);

        $reponse = $this->postJson('/api/rental/bookings/'.Cipher::Encrypt($seconde->id).'/deposit', [
            'method' => 'mobile', 'phone' => '+243810000000',
        ]);

        $reponse->assertStatus(400);
        $reponse->assertJsonPath('error', BookingRefusee::PAIEMENT_EN_COURS);
    }
}
