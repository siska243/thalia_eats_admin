<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthHardeningTest extends TestCase
{
    use DatabaseTruncation;

    public function test_un_code_d_activation_expire_est_refuse(): void
    {
        // otp_expire_at etait renseigne a l'inscription mais jamais relu :
        // un code d'activation restait valable indefiniment.
        User::factory()->create([
            'email' => 'client@thalia.test',
            'otp' => '1234',
            'otp_expire_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/activation-account', [
            'email' => 'client@thalia.test',
            'otp' => '1234',
        ])->assertStatus(400);
    }

    public function test_un_code_d_activation_valide_est_accepte(): void
    {
        $user = User::factory()->create([
            'email' => 'client@thalia.test',
            'otp' => '1234',
            'otp_expire_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/activation-account', [
            'email' => 'client@thalia.test',
            'otp' => '1234',
        ])->assertStatus(201);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_la_connexion_ne_revele_pas_si_l_email_existe(): void
    {
        User::factory()->create([
            'email' => 'connu@thalia.test',
            'password' => Hash::make('bon-mot-de-passe'),
        ]);

        $mauvaisMotDePasse = $this->postJson('/api/login', [
            'email' => 'connu@thalia.test',
            'password' => 'mauvais',
        ]);

        $emailInconnu = $this->postJson('/api/login', [
            'email' => 'inconnu@thalia.test',
            'password' => 'mauvais',
        ]);

        // « Email incorrect » revelait qu'une adresse n'existe pas,
        // « Password incorrect » qu'elle existe : de quoi enumerer les comptes.
        $this->assertSame($mauvaisMotDePasse->status(), $emailInconnu->status());
        $this->assertSame(
            $mauvaisMotDePasse->json('message'),
            $emailInconnu->json('message')
        );
    }
}
