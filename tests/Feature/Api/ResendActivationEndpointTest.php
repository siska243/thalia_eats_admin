<?php

namespace Tests\Feature\Api;

use App\Mail\WelcomeOtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Les codes d'activation expirent au bout de trente minutes. Sans ce point
 * d'entree, un compte dont le code a expire ne pourrait plus jamais etre
 * active.
 */
class ResendActivationEndpointTest extends TestCase
{
    use DatabaseTruncation;

    public function test_un_nouveau_code_remplace_le_code_expire(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'client@thalia.test',
            'email_verified_at' => null,
            'otp' => '1111',
            'otp_expire_at' => now()->subHour(),
        ]);

        $this->postJson('/api/resend-activation', ['email' => 'client@thalia.test'])
            ->assertStatus(200);

        Mail::assertSent(WelcomeOtpMail::class);

        $user->refresh();

        $this->assertNotSame('1111', $user->otp);
        $this->assertTrue($user->otp_expire_at->isFuture());
    }

    public function test_aucun_code_n_est_renvoye_a_un_compte_deja_active(): void
    {
        Mail::fake();

        User::factory()->create([
            'email' => 'client@thalia.test',
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/resend-activation', ['email' => 'client@thalia.test'])
            ->assertStatus(200);

        Mail::assertNothingSent();
    }

    public function test_l_inscription_ne_renvoie_pas_le_code_dans_sa_reponse(): void
    {
        Mail::fake();

        // DatabaseTruncation vide aussi la table des roles, et register()
        // appelle assignRole('client').
        Role::findOrCreate('client', config('auth.defaults.guard'));

        $response = $this->postJson('/api/register', [
            'name' => 'Kabila',
            'last_name' => 'Marie',
            'email' => 'nouvelle@thalia.test',
            'phone' => '+243810000000',
            'password' => 'motdepasse',
            'confirm_password' => 'motdepasse',
        ]);

        $response->assertStatus(201);

        // Retourner le code rendait la verification par email decorative.
        $this->assertArrayNotHasKey('otp', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('otp_expire_at', $response->json('data') ?? []);
    }

    public function test_la_reponse_ne_revele_pas_si_le_compte_existe(): void
    {
        Mail::fake();

        User::factory()->create([
            'email' => 'connu@thalia.test',
            'email_verified_at' => null,
        ]);

        $connu = $this->postJson('/api/resend-activation', ['email' => 'connu@thalia.test']);
        $inconnu = $this->postJson('/api/resend-activation', ['email' => 'inconnu@thalia.test']);

        $this->assertSame($connu->status(), $inconnu->status());
        $this->assertSame($connu->json('message'), $inconnu->json('message'));
    }
}
