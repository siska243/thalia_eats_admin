<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le defi anti-robot sur l'inscription du site.
 *
 * Le widget seul ne protege rien : sans verification au serveur, il suffit
 * d'appeler l'API directement pour le contourner. Ces tests portent donc sur
 * la verification, pas sur l'affichage.
 */
class TurnstileInscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // L'inscription envoie le code de bienvenue : sans ce faux, le test
        // depend de la configuration de messagerie de la machine.
        Mail::fake();

        // L'inscription attache le role `client` : il est cree par un seeder,
        // que la base de test ne joue pas.
        Role::findOrCreate('client', 'web');
    }

    private function inscription(array $extra = []): array
    {
        return array_merge([
            'name' => 'Eva',
            'last_name' => 'Mbala',
            'email' => 'eva@example.com',
            'phone' => '+243810000000',
            'password' => 'motdepasse',
            'password_confirmation' => 'motdepasse',
        ], $extra);
    }

    /**
     * ETEINT PAR DEFAUT.
     *
     * Sans cle, l'inscription se comporte exactement comme avant — c'est ce
     * qui protege les clients mobiles deja installes, qui n'envoient aucun
     * jeton.
     */
    public function test_sans_cle_l_inscription_passe_sans_jeton(): void
    {
        config(['securite.turnstile.secret' => null]);
        Http::fake();

        $this->postJson('/api/register', $this->inscription())->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_avec_une_cle_un_jeton_absent_est_refuse(): void
    {
        config(['securite.turnstile.secret' => 'secret-de-test']);

        $reponse = $this->postJson('/api/register', $this->inscription());

        $reponse->assertStatus(400);
        $this->assertSame('turnstile_echoue', $reponse->json('error'));
    }

    public function test_un_jeton_refuse_par_cloudflare_est_refuse(): void
    {
        config(['securite.turnstile.secret' => 'secret-de-test']);
        Http::fake(['*challenges.cloudflare.com*' => Http::response(['success' => false])]);

        $this->postJson('/api/register', $this->inscription(['turnstile_token' => 'faux']))
            ->assertStatus(400);
    }

    public function test_un_jeton_valide_laisse_passer(): void
    {
        config(['securite.turnstile.secret' => 'secret-de-test']);
        Http::fake(['*challenges.cloudflare.com*' => Http::response(['success' => true])]);

        $this->postJson('/api/register', $this->inscription(['turnstile_token' => 'bon']))
            ->assertSuccessful();
    }

    /**
     * Cloudflare injoignable ne ferme pas les inscriptions.
     *
     * Le risque d'un robot qui entre pendant une panne reseau est moindre
     * que celui de perdre tous les vrais clients pendant la meme panne.
     */
    public function test_cloudflare_injoignable_laisse_passer(): void
    {
        config(['securite.turnstile.secret' => 'secret-de-test']);
        Http::fake(fn () => throw new \RuntimeException('reseau coupe'));

        $this->postJson('/api/register', $this->inscription(['turnstile_token' => 'bon']))
            ->assertSuccessful();
    }
}
