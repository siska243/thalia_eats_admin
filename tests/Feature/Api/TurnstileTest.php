<?php

namespace Tests\Feature\Api;

use App\Mail\WelcomeOtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le defi anti-robot, sur les trois routes qu'il protege.
 *
 * Inscription, connexion et renvoi du code : les trois ouvrent ou reouvrent
 * un acces, et les trois etaient atteignables par une machine. Le renvoi du
 * code n'etait meme retenu que par un delai pose dans l'ecran.
 *
 * Le widget seul ne protege rien : sans verification au serveur, il suffit
 * d'appeler l'API directement pour le contourner. Ces tests portent donc sur
 * la verification, pas sur l'affichage.
 */
class TurnstileTest extends TestCase
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
     * Cloudflare injoignable : on REFUSE.
     *
     * Le contraire rendait la mesure nulle sans que personne ne s'en
     * apercoive : il suffisait d'empecher l'appel sortant pour eteindre le
     * defi, et seul un `warning` dans le journal l'aurait dit.
     */
    public function test_cloudflare_injoignable_est_refuse(): void
    {
        config(['securite.turnstile.secret' => 'secret-de-test']);
        Http::fake(fn () => throw new \RuntimeException('reseau coupe'));

        $reponse = $this->postJson('/api/register', $this->inscription(['turnstile_token' => 'bon']));

        $reponse->assertStatus(400);
        $this->assertSame('turnstile_echoue', $reponse->json('error'));
    }

    /**
     * L'interrupteur de secours, et pourquoi il existe.
     *
     * Si Cloudflare tombe vraiment, refuser ferme toutes les connexions du
     * site. Ce jour-la, rouvrir doit tenir a une variable d'environnement,
     * pas a une livraison.
     */
    public function test_l_interrupteur_de_secours_laisse_passer(): void
    {
        config([
            'securite.turnstile.secret' => 'secret-de-test',
            'securite.turnstile.fail_open' => true,
        ]);
        Http::fake(fn () => throw new \RuntimeException('reseau coupe'));

        $this->postJson('/api/register', $this->inscription(['turnstile_token' => 'bon']))
            ->assertSuccessful();
    }

    // --- La connexion --------------------------------------------------------

    /**
     * @param  array<string, mixed>  $extra
     */
    private function compte(array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'eva@example.com',
            'password' => Hash::make('motdepasse'),
            'email_verified_at' => now(),
        ], $extra));
    }

    /**
     * Sans cle, la connexion se comporte comme avant.
     *
     * C'est ce qui protege le parc mobile deja installe : il n'envoie aucun
     * jeton, et poser la cle secrete l'empecherait de se connecter.
     */
    public function test_sans_cle_la_connexion_passe_sans_jeton(): void
    {
        config(['securite.turnstile.secret' => null]);
        $this->compte();
        Http::fake();

        $this->postJson('/api/login', [
            'email' => 'eva@example.com',
            'password' => 'motdepasse',
        ])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_la_connexion_refuse_un_jeton_absent(): void
    {
        config(['securite.turnstile.secret' => 'secret-de-test']);
        $this->compte();

        $reponse = $this->postJson('/api/login', [
            'email' => 'eva@example.com',
            'password' => 'motdepasse',
        ]);

        $reponse->assertStatus(400);
        $this->assertSame('turnstile_echoue', $reponse->json('error'));
    }

    /** Le defi passe AVANT le mot de passe : un robot n'apprend rien. */
    public function test_la_connexion_refuse_avant_de_juger_le_mot_de_passe(): void
    {
        config(['securite.turnstile.secret' => 'secret-de-test']);
        $this->compte();

        $reponse = $this->postJson('/api/login', [
            'email' => 'eva@example.com',
            'password' => 'mauvais-mot-de-passe',
        ]);

        $this->assertSame('turnstile_echoue', $reponse->json('error'));
    }

    public function test_la_connexion_passe_avec_un_jeton_valide(): void
    {
        config(['securite.turnstile.secret' => 'secret-de-test']);
        $this->compte();
        Http::fake(['*challenges.cloudflare.com*' => Http::response(['success' => true])]);

        $this->postJson('/api/login', [
            'email' => 'eva@example.com',
            'password' => 'motdepasse',
            'turnstile_token' => 'bon',
        ])->assertSuccessful();
    }

    // --- Le renvoi du code ---------------------------------------------------

    /**
     * Le renvoi n'etait retenu que par un delai de 60 s pose dans l'ecran.
     *
     * Un delai cote client ne protege rien : la route s'appelle directement.
     */
    public function test_le_renvoi_du_code_refuse_un_jeton_absent(): void
    {
        config(['securite.turnstile.secret' => 'secret-de-test']);
        $this->compte(['email_verified_at' => null]);

        $reponse = $this->postJson('/api/resend-activation', ['email' => 'eva@example.com']);

        $reponse->assertStatus(400);
        $this->assertSame('turnstile_echoue', $reponse->json('error'));
        Mail::assertNothingSent();
    }

    public function test_le_renvoi_du_code_passe_avec_un_jeton_valide(): void
    {
        config(['securite.turnstile.secret' => 'secret-de-test']);
        $this->compte(['email_verified_at' => null]);
        Http::fake(['*challenges.cloudflare.com*' => Http::response(['success' => true])]);

        $this->postJson('/api/resend-activation', [
            'email' => 'eva@example.com',
            'turnstile_token' => 'bon',
        ])->assertSuccessful();

        Mail::assertSent(WelcomeOtpMail::class);
    }

    public function test_sans_cle_le_renvoi_du_code_passe_comme_avant(): void
    {
        config(['securite.turnstile.secret' => null]);
        $this->compte(['email_verified_at' => null]);
        Http::fake();

        $this->postJson('/api/resend-activation', ['email' => 'eva@example.com'])
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
