<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Le code OTP fait quatre chiffres : dix mille combinaisons. Sans limitation
 * dediee, le seul throttle:api (60 par minute) laisse parcourir tout l'espace
 * en quelques heures, et comme reset-password pose un nouveau mot de passe a
 * partir de ce code, c'est une prise de compte.
 *
 * On verifie ici que les points d'entree sensibles (connexion et codes OTP)
 * finissent par refuser la rafale avec un 429, et pas par la laisser passer
 * indefiniment comme avant ce correctif.
 */
class AuthBruteForceTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        // Le limiteur vit dans le cache : on part d'un compteur vierge pour
        // que le seuil mesure soit bien celui de ce test.
        RateLimiter::clear('auth-sensibles');
    }

    public function test_les_essais_d_otp_sur_reset_password_finissent_bloques(): void
    {
        User::factory()->create([
            'email' => 'cible@thalia.test',
            'otp' => '1234',
            'otp_expire_at' => now()->addMinutes(30),
        ]);

        $bloque = false;

        // Bien au-dela de tout usage legitime : un humain ne saisit pas
        // quarante codes d'affilee. La rafale doit buter sur un 429 avant la
        // fin, sinon l'espace des codes se parcourt.
        for ($i = 0; $i < 40; $i++) {
            $reponse = $this->postJson('/api/reset-password', [
                'email' => 'cible@thalia.test',
                'otp' => str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'password' => 'NouveauMotDePasse1',
                'confirm_password' => 'NouveauMotDePasse1',
            ]);

            if ($reponse->status() === 429) {
                $bloque = true;
                break;
            }
        }

        $this->assertTrue($bloque, 'reset-password doit finir par refuser la rafale avec un 429.');
    }

    public function test_les_essais_de_mot_de_passe_sur_login_finissent_bloques(): void
    {
        User::factory()->create([
            'email' => 'victime@thalia.test',
            'password' => Hash::make('leBonMotDePasse1'),
        ]);

        $bloque = false;

        for ($i = 0; $i < 40; $i++) {
            $reponse = $this->postJson('/api/login', [
                'email' => 'victime@thalia.test',
                'password' => 'faux-'.$i,
            ]);

            if ($reponse->status() === 429) {
                $bloque = true;
                break;
            }
        }

        $this->assertTrue($bloque, 'login doit finir par refuser la rafale avec un 429.');
    }
}
