<?php

namespace Tests\Feature\Rental;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\ConfigurationPayement;
use App\Models\Currency;
use App\Models\StatusPayement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Rental\DepositWebhook;
use App\Settings\RentalSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Le paiement de l'acompte, de l'engagement au retour de la passerelle. */
class RentalDepositTest extends TestCase
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
            'hours_min' => 1,
            'hours_max' => 23,
            'days_min' => 1,
            'days_max' => 30,
            'whatsapp_number' => '',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Nous ne desservons pas encore cette commune.',
        ], loadMissingValues: false);

        ConfigurationPayement::query()->create([
            'token' => 'jeton', 'token_key' => 'marchand', 'active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        StatusPayement::query()->create([
            'code' => '0', 'name' => 'Payé', 'is_paid' => true,
        ]);
        StatusPayement::query()->create([
            'code' => '1', 'name' => 'Échoué', 'is_paid' => false,
        ]);

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

    private function reservation(array $attributs = []): Booking
    {
        return Booking::query()->create(array_merge([
            'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => Carbon::parse('+2 days 10:00'),
            'ends_at' => Carbon::parse('+2 days 15:00'),
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
        ], $attributs));
    }

    /** La passerelle accepte l'appel et rend un numero de transaction. */
    private function passerelleAccepte(): void
    {
        Http::fake([
            '*/paymentService' => Http::response(['code' => '0', 'orderNumber' => 'FX-1'], 200),
        ]);
    }

    private function verificationRend(array $transaction, string $code = '0'): void
    {
        Http::fake([
            '*/check/*' => Http::response(['code' => $code, 'transaction' => $transaction], 200),
        ]);
    }

    public function test_le_client_engage_l_acompte(): void
    {
        $this->passerelleAccepte();
        $booking = $this->reservation();
        Sanctum::actingAs($this->client, ['*']);

        $this->postJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/deposit', [
            'method' => 'mobile', 'phone' => '+243810000000',
        ])->assertSuccessful()->assertJsonPath('data.order_number', 'FX-1');

        // Le montant envoye a la passerelle est celui de la reservation.
        Http::assertSent(fn ($request) => (float) $request['amount'] === 5.0
            && $request['reference'] === $booking->reference);

        // Une tentative est tracee, mais rien n'est encaisse.
        $tentative = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('FX-1', $tentative->reference);
        $this->assertNull($tentative->paid_at);

        // Le vehicule reste libre tant que l'argent n'est pas arrive.
        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $booking->refresh()->status);
    }

    /**
     * Le client revient dans l'application, pas sur le site.
     *
     * Le paiement d'une commande de repas transmet ses adresses de retour de
     * la meme facon. Sans elles, un paiement par carte deposait le client sur
     * le site web a la fin, et il devait rouvrir l'application a la main.
     */
    public function test_les_adresses_de_retour_du_client_sont_transmises(): void
    {
        Http::fake([
            '*' => Http::response(['code' => '0', 'orderNumber' => 'FX-1', 'url' => 'https://carte.flexpay.cd/p/1'], 200),
        ]);

        $booking = $this->reservation();
        Sanctum::actingAs($this->client, ['*']);

        $this->postJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/deposit', [
            'method' => 'cart',
            'success_url' => 'thalia-eats-app://custom-screens/checkout?slug=success',
            'error_url' => 'thalia-eats-app://custom-screens/checkout?slug=error',
            'cancel_url' => 'thalia-eats-app://custom-screens/checkout?slug=cancel',
        ])
            ->assertSuccessful()
            // La passerelle carte rend l'adresse de sa page de saisie.
            ->assertJsonPath('data.url', 'https://carte.flexpay.cd/p/1');

        Http::assertSent(function ($request) {
            return $request['approve_url'] === 'thalia-eats-app://custom-screens/checkout?slug=success'
                && $request['cancel_url'] === 'thalia-eats-app://custom-screens/checkout?slug=cancel'
                && $request['decline_url'] === 'thalia-eats-app://custom-screens/checkout?slug=error'
                // L'adresse du webhook ne vient jamais du client.
                && $request['callback_url'] === config('flexpay.callback_url');
        });
    }

    /** Sans adresses de retour, le comportement d'avant : on retombe sur le site. */
    public function test_sans_adresses_de_retour_on_retombe_sur_le_site(): void
    {
        $this->passerelleAccepte();

        $booking = $this->reservation();
        Sanctum::actingAs($this->client, ['*']);

        $this->postJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/deposit', [
            'method' => 'mobile', 'phone' => '+243810000000',
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => $request['approve_url'] === config('app.url'));
    }

    public function test_une_reservation_deja_confirmee_n_engage_rien(): void
    {
        $this->passerelleAccepte();
        $booking = $this->reservation(['status' => Booking::STATUS_CONFIRMED]);
        Sanctum::actingAs($this->client, ['*']);

        $this->postJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/deposit', [
            'method' => 'mobile', 'phone' => '+243810000000',
        ])->assertStatus(400)->assertJsonPath('error', 'acompte_non_attendu');

        Http::assertNothingSent();
    }

    public function test_un_numero_invalide_est_refuse(): void
    {
        $this->passerelleAccepte();
        $booking = $this->reservation();
        Sanctum::actingAs($this->client, ['*']);

        $this->postJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/deposit', [
            'method' => 'mobile', 'phone' => 'pas-un-numero',
        ])->assertStatus(400)->assertJsonPath('error', 'telephone_invalide');

        Http::assertNothingSent();
    }

    public function test_on_n_engage_pas_l_acompte_d_un_autre(): void
    {
        $this->passerelleAccepte();
        $booking = $this->reservation(['user_id' => User::factory()->create()->id]);
        Sanctum::actingAs($this->client, ['*']);

        $this->postJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/deposit', [
            'method' => 'mobile', 'phone' => '+243810000000',
        ])->assertStatus(404);
    }

    public function test_le_webhook_confirme_la_reservation(): void
    {
        $booking = $this->reservation();
        $booking->payments()->create([
            'kind' => BookingPayment::KIND_DEPOSIT, 'amount' => 5,
            'currency_id' => $this->currency->id, 'reference' => 'FX-1',
        ]);

        $this->verificationRend([
            'reference' => $booking->reference, 'amount' => 5, 'status' => '0',
        ]);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $booking->reference, 'orderNumber' => 'FX-1',
            'channel' => 'MPESA', 'phone' => '243810000000',
            'provider_reference' => 'OP-9',
        ])->assertSuccessful()->assertJsonPath('code', 'confirmee');

        $booking->refresh();
        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->status);
        $this->assertNotNull($booking->confirmed_at);
        // Le code de prise en charge n'existe qu'a partir de maintenant.
        $this->assertMatchesRegularExpression('/^\d{4}$/', $booking->pickup_code);

        // Une seule ligne de paiement : la tentative a ete soldee, pas doublee.
        $this->assertSame(1, $booking->payments()->count());
        $paiement = $booking->payments()->first();
        $this->assertNotNull($paiement->paid_at);
        $this->assertSame('MPESA', $paiement->channel);
        $this->assertSame('OP-9', $paiement->provider_reference);
    }

    public function test_le_webhook_refuse_une_reference_qui_ne_correspond_pas(): void
    {
        $booking = $this->reservation();

        // Une transaction reellement payee, mais qui porte une autre reference.
        $this->verificationRend(['reference' => 'R-AUTRE', 'amount' => 5, 'status' => '0']);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $booking->reference, 'orderNumber' => 'FX-1',
        ])->assertSuccessful()->assertJsonPath('code', 'reference_incoherente');

        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $booking->refresh()->status);
    }

    public function test_le_webhook_refuse_un_montant_different_de_l_acompte(): void
    {
        $booking = $this->reservation();

        $this->verificationRend([
            'reference' => $booking->reference, 'amount' => 1, 'status' => '0',
        ]);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $booking->reference, 'orderNumber' => 'FX-1',
        ])->assertSuccessful()->assertJsonPath('code', 'montant_incoherent');

        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $booking->refresh()->status);
    }

    public function test_le_webhook_refuse_une_transaction_sans_montant(): void
    {
        $booking = $this->reservation();

        $this->verificationRend(['reference' => $booking->reference, 'status' => '0']);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $booking->reference, 'orderNumber' => 'FX-1',
        ])->assertSuccessful()->assertJsonPath('code', 'montant_incoherent');
    }

    public function test_un_paiement_non_abouti_ne_confirme_rien(): void
    {
        $booking = $this->reservation();
        $booking->payments()->create([
            'kind' => BookingPayment::KIND_DEPOSIT, 'amount' => 5,
            'currency_id' => $this->currency->id, 'reference' => 'FX-1',
        ]);

        $this->verificationRend([
            'reference' => $booking->reference, 'amount' => 5, 'status' => '1',
        ]);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $booking->reference, 'orderNumber' => 'FX-1',
        ])->assertSuccessful()->assertJsonPath('code', 'paiement_non_abouti');

        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $booking->refresh()->status);
        $this->assertNotNull($booking->payments()->first()->failed_at);
    }

    /** FlexPay rejoue ses webhooks : le second passage ne doit rien encaisser. */
    public function test_le_webhook_rejoue_n_encaisse_pas_deux_fois(): void
    {
        $booking = $this->reservation();

        $this->verificationRend([
            'reference' => $booking->reference, 'amount' => 5, 'status' => '0',
        ]);

        $envoi = fn () => $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $booking->reference, 'orderNumber' => 'FX-1',
        ]);

        $envoi()->assertJsonPath('code', 'confirmee');
        $code = $booking->refresh()->pickup_code;

        $envoi()->assertJsonPath('code', 'deja_traite');

        $this->assertSame(1, $booking->payments()->count());
        // Le code ne bouge pas : le client l'a peut-etre deja note.
        $this->assertSame($code, $booking->refresh()->pickup_code);
    }

    /** Un autre a paye entre-temps : le creneau est perdu, l'acompte du. */
    public function test_le_perdant_passe_en_lost_avec_son_remboursement(): void
    {
        $booking = $this->reservation();

        $this->reservation([
            'user_id' => User::factory()->create()->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        $this->verificationRend([
            'reference' => $booking->reference, 'amount' => 5, 'status' => '0',
        ]);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => $booking->reference, 'orderNumber' => 'FX-1',
        ])->assertSuccessful()->assertJsonPath('code', 'perdue');

        $booking->refresh();
        $this->assertSame(Booking::STATUS_LOST, $booking->status);
        $this->assertSame(5.0, (float) $booking->refund_amount);
        $this->assertNull($booking->pickup_code);
    }

    public function test_une_reference_de_repas_ne_part_pas_dans_le_chemin_location(): void
    {
        // La separation tient au prefixe : une commande de repas porte un
        // entier nu, une pre-commande « P- ». Ni l'une ni l'autre ne peut
        // etre prise pour une location.
        $this->assertFalse(DepositWebhook::concerne('12345'));
        $this->assertFalse(DepositWebhook::concerne('P-ABCDEF'));
        $this->assertTrue(DepositWebhook::concerne('R-ABCDEF'));
    }
}
