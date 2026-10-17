<?php

namespace Tests\Feature\Api;

use App\Mail\CommandePayeeMail;
use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Status;
use App\Models\User;
use App\Services\Notification\NotifierCommandePayee;
use App\Settings\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Prevenir, quand une commande est payee.
 *
 * Le proprietaire, le 17 octobre : « une notification push + email a chaque
 * fois qu'on commande. l'adresse email de reception, tu mets plusieurs. le
 * client aussi de recevoir une copie par email et le restaurant egalement ».
 *
 * Avant, une commande payee n'envoyait qu'un push au restaurateur, et
 * seulement s'il avait un jeton : une commande pouvait arriver sans que
 * personne au bureau le sache.
 */
class NotificationCommandeTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $restaurateur;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->reglages();

        Status::query()->firstOrCreate(['id' => 2], ['title' => 'En cours', 'slug' => 2]);

        $this->client = User::factory()->create(['email' => 'eva@example.com']);
        $this->restaurateur = User::factory()->create(['email' => 'chef@example.com']);
    }

    private function reglages(string $adminsBruts = '', bool $client = true, bool $restaurant = true): void
    {
        NotificationSettings::fake([
            'emails_commande' => $adminsBruts,
            'copie_client' => $client,
            'copie_restaurant' => $restaurant,
        ], loadMissingValues: false);
    }

    private function commande(): Commande
    {
        $devise = Currency::factory()->create(['code' => 'USD', 'is_active' => 1]);

        $restaurant = Restaurant::factory()->create(['user_id' => $this->restaurateur->id]);

        $produit = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $devise->id,
            'title' => 'Poulet braisé',
            'price' => 10.0,
        ]);

        $commande = Commande::query()->create([
            'user_id' => $this->client->id,
            'status_id' => 2,
            'refernce' => 'CMD-'.uniqid(),
            'global_price' => 25.0,
            'price_delivery' => 5.0,
            'price_service' => 0.0,
            'adresse_delivery' => 'Avenue du Commerce, Gombe',
            'paied_at' => now(),
        ]);

        CommandeProduct::query()->create([
            'commande_id' => $commande->id,
            'user_id' => $this->client->id,
            'product_id' => $produit->id,
            'quantity' => 2,
            'price' => 10.0,
            'currency_id' => $devise->id,
        ]);

        return $commande->fresh();
    }

    private function notifier(Commande $commande): void
    {
        app(NotifierCommandePayee::class)($commande);
    }

    // --- Les trois destinataires --------------------------------------------

    public function test_l_equipe_recoit_un_courriel_par_adresse_reglee(): void
    {
        $this->reglages("direction@thaliaeats.com\ncommandes@thaliaeats.com");

        $this->notifier($this->commande());

        Mail::assertSent(CommandePayeeMail::class, fn ($mail) => $mail->hasTo('direction@thaliaeats.com'));
        Mail::assertSent(CommandePayeeMail::class, fn ($mail) => $mail->hasTo('commandes@thaliaeats.com'));
    }

    /** Virgules, points-virgules, retours a la ligne : on colle ce qu'on a. */
    public function test_les_adresses_se_separent_par_virgule_aussi(): void
    {
        $this->reglages('un@example.com, deux@example.com ; trois@example.com');

        $this->assertSame(
            ['un@example.com', 'deux@example.com', 'trois@example.com'],
            NotificationSettings::destinatairesAdmin(),
        );
    }

    /**
     * Une adresse mal saisie ne fait pas tomber les autres.
     *
     * C'est le back-office qui remplit ce champ, a la main : une faute de
     * frappe ne doit pas priver toute l'equipe de ses courriels.
     */
    public function test_une_adresse_invalide_est_ignoree_sans_bloquer_les_autres(): void
    {
        $this->reglages('pas-une-adresse, bon@example.com');

        $this->assertSame(['bon@example.com'], NotificationSettings::destinatairesAdmin());
    }

    public function test_le_client_recoit_sa_copie(): void
    {
        $this->notifier($this->commande());

        Mail::assertSent(CommandePayeeMail::class, fn ($mail) => $mail->hasTo('eva@example.com'));
    }

    public function test_le_restaurant_recoit_sa_copie(): void
    {
        $this->notifier($this->commande());

        Mail::assertSent(CommandePayeeMail::class, fn ($mail) => $mail->hasTo('chef@example.com'));
    }

    public function test_les_copies_s_eteignent_depuis_les_reglages(): void
    {
        $this->reglages(client: false, restaurant: false);

        $this->notifier($this->commande());

        Mail::assertNothingSent();
    }

    /** Vide, aucun courriel d'equipe ne part : c'est l'etat d'avant. */
    public function test_sans_adresse_reglee_aucun_courriel_d_equipe(): void
    {
        $this->reglages(client: false, restaurant: false);

        $this->notifier($this->commande());

        Mail::assertNothingSent();
    }

    // --- L'idempotence ------------------------------------------------------

    /**
     * LE WEBHOOK PEUT ETRE REJOUE.
     *
     * Son propre commentaire le dit, et ce chemin ecrit `paied_at` sans
     * condition. Sans la marque, le client recevrait son recapitulatif
     * autant de fois que la passerelle rappelle.
     */
    public function test_notifier_deux_fois_n_envoie_qu_une_fois(): void
    {
        $this->reglages('direction@thaliaeats.com');

        $commande = $this->commande();

        $this->notifier($commande);
        $this->notifier($commande->fresh());

        Mail::assertSent(CommandePayeeMail::class, 3);
    }

    public function test_la_marque_est_posee_sur_la_commande(): void
    {
        $commande = $this->commande();

        $this->assertNull($commande->notifiee_at);

        $this->notifier($commande);

        $this->assertNotNull($commande->fresh()->notifiee_at);
    }

    // --- Rien ne doit casser un paiement ------------------------------------

    /**
     * Un serveur de courriel muet ne fait pas echouer le paiement.
     *
     * La commande est payee, c'est ce qui compte : un courriel perdu se
     * renvoie, un paiement perdu non.
     */
    public function test_un_envoi_qui_echoue_ne_leve_pas(): void
    {
        $this->reglages('direction@thaliaeats.com');

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $commande = $this->commande();

        $this->notifier($commande);

        $this->assertNotNull($commande->fresh()->notifiee_at);
    }
}
