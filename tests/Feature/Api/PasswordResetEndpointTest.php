<?php

namespace Tests\Feature\Api;

use App\Mail\OtpReinitPasswordMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetEndpointTest extends TestCase
{
    use DatabaseTruncation;

    public function test_un_code_est_envoye_au_compte_existant(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'client@thalia.test']);

        $this->postJson('/api/forgot-password', ['email' => 'client@thalia.test'])
            ->assertStatus(200);

        Mail::assertSent(OtpReinitPasswordMail::class);

        $this->assertNotNull($user->fresh()->otp);
        $this->assertNotNull($user->fresh()->otp_expire_at);
    }

    public function test_la_reponse_ne_revele_pas_si_le_compte_existe(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'connu@thalia.test']);

        $connu = $this->postJson('/api/forgot-password', ['email' => 'connu@thalia.test']);
        $inconnu = $this->postJson('/api/forgot-password', ['email' => 'inconnu@thalia.test']);

        // Une reponse differente transformerait l'endpoint en outil
        // d'enumeration des comptes.
        $this->assertSame($connu->status(), $inconnu->status());
        $this->assertSame($connu->json('message'), $inconnu->json('message'));

        Mail::assertSentCount(1);
    }

    public function test_le_mot_de_passe_est_change_avec_un_code_valide(): void
    {
        $user = User::factory()->create([
            'email' => 'client@thalia.test',
            'password' => Hash::make('ancien-mot-de-passe'),
            'otp' => '1234',
            'otp_expire_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/reset-password', [
            'email' => 'client@thalia.test',
            'otp' => '1234',
            'password' => 'nouveau-mot-de-passe',
            'confirm_password' => 'nouveau-mot-de-passe',
        ])->assertStatus(200);

        $user->refresh();

        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $user->password));
        $this->assertNull($user->otp);
    }

    public function test_un_code_expire_est_refuse(): void
    {
        User::factory()->create([
            'email' => 'client@thalia.test',
            'password' => Hash::make('ancien-mot-de-passe'),
            'otp' => '1234',
            'otp_expire_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/reset-password', [
            'email' => 'client@thalia.test',
            'otp' => '1234',
            'password' => 'nouveau-mot-de-passe',
            'confirm_password' => 'nouveau-mot-de-passe',
        ])->assertStatus(400);
    }

    public function test_les_sessions_ouvertes_sont_revoquees(): void
    {
        $user = User::factory()->create([
            'email' => 'client@thalia.test',
            'otp' => '1234',
            'otp_expire_at' => now()->addMinutes(10),
        ]);

        $user->createToken('api token');
        $this->assertSame(1, $user->tokens()->count());

        $this->postJson('/api/reset-password', [
            'email' => 'client@thalia.test',
            'otp' => '1234',
            'password' => 'nouveau-mot-de-passe',
            'confirm_password' => 'nouveau-mot-de-passe',
        ])->assertStatus(200);

        // Si le compte etait compromis, l'ancien acces doit tomber avec le
        // mot de passe.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_les_deux_saisies_doivent_correspondre(): void
    {
        User::factory()->create([
            'email' => 'client@thalia.test',
            'otp' => '1234',
            'otp_expire_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/reset-password', [
            'email' => 'client@thalia.test',
            'otp' => '1234',
            'password' => 'nouveau-mot-de-passe',
            'confirm_password' => 'autre-chose',
        ])->assertStatus(400);
    }
}
