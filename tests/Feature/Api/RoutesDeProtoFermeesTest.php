<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Trois routes de prototype etaient restees ouvertes a tout le monde.
 *
 * - `POST /api/ia-model-llama3` relaie le corps recu vers un Ollama local en
 *   127.0.0.1:11434, sans authentification ni limitation, avec un delai
 *   d'attente d'une heure. N'importe qui sur Internet pouvait donc faire
 *   tourner le GPU du serveur gratuitement, et surtout tenir une heure de
 *   connexion par requete : quelques dizaines suffisent a epuiser php-fpm.
 *   Aucun des trois clients ne l'appelle, c'est verifie par grep ;
 * - `GET /api/roles` CREE quatre roles spatie, sans authentification ;
 * - `GET /api/notification` declenche un envoi de notification push, sans
 *   authentification.
 *
 * Aucune n'est supprimee : une suppression demande une depreciation et un
 * build mobile, ce n'est pas la decision de ce commit.
 */
class RoutesDeProtoFermeesTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proxy_ia_refuse_un_appel_anonyme(): void
    {
        Http::fake();

        $this->postJson('/api/ia-model-llama3', ['prompt' => 'bonjour'])
            ->assertStatus(401);

        // Rien ne part vers Ollama : le refus precede l'appel sortant.
        Http::assertNothingSent();
    }

    public function test_le_proxy_ia_reste_ouvert_a_un_compte_authentifie(): void
    {
        Http::fake(['127.0.0.1:11434/*' => Http::response(['response' => 'ok'])]);

        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/ia-model-llama3', ['prompt' => 'bonjour'])
            ->assertStatus(200);
    }

    /**
     * create-payment-intent ouvrait une intention de paiement Stripe a chaque
     * appel, sans authentification et sur un montant fixe de 1099. Aucun des
     * trois clients ne l'appelle. La cle secrete Stripe, elle, est ecrite en
     * clair dans un enum du depot : elle passe par la configuration, en
     * gardant la valeur actuelle par defaut pour ne rien casser.
     */
    public function test_l_ouverture_d_une_intention_stripe_refuse_un_appel_anonyme(): void
    {
        $this->postJson('/api/create-payment-intent', ['url' => 'https://app.thaliaeats.com'])
            ->assertStatus(401);
    }

    public function test_la_cle_stripe_se_lit_dans_la_configuration(): void
    {
        // Le point d'entree existe et porte une valeur : le proprietaire n'a
        // plus qu'a poser STRIPE_SECRET dans son .env pour que la cle
        // committee cesse de servir.
        $this->assertNotEmpty(config('services.stripe.secret'));
    }

    public function test_la_creation_de_roles_n_est_plus_joignable(): void
    {
        $avant = Role::query()->count();

        $this->getJson('/api/roles')->assertStatus(404);

        $this->assertSame($avant, Role::query()->count());
    }

    public function test_l_envoi_de_notification_d_essai_n_est_plus_joignable(): void
    {
        $this->getJson('/api/notification')->assertStatus(404);
    }
}
