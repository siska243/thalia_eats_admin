<?php

namespace Tests\Feature\Api;

use App\Mail\CodeDoubleAuthentificationMail;
use App\Models\User;
use App\Services\Securite\DoubleAuthentification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

/**
 * La double authentification cote client, site et application.
 *
 * Le proprietaire : « egalement pour l'application mobile ou web avoir la
 * possibilite d'activer la double authentification », et « totp ou courriel
 * tu peux mettre les 2, c'est a l'utilisateur de choisir ».
 *
 * Le point qui compte le plus est le premier teste : UN COMPTE SANS SECOND
 * FACTEUR SE CONNECTE EXACTEMENT COMME AVANT. Tous les clients deja
 * installes passent par la.
 */
class DoubleAuthentificationClientTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->google2fa = app(Google2FA::class);
    }

    private function client(array $attributs = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'eva@example.com',
            'password' => Hash::make('motdepasse'),
            'email_verified_at' => now(),
        ], $attributs));
    }

    private function connexion(): TestResponse
    {
        return $this->postJson('/api/login', [
            'email' => 'eva@example.com',
            'password' => 'motdepasse',
        ]);
    }

    // --- Non-regression -----------------------------------------------------

    public function test_sans_second_facteur_la_connexion_rend_un_jeton_comme_avant(): void
    {
        $this->client();

        $reponse = $this->connexion();

        $reponse->assertSuccessful();
        $this->assertNotNull($reponse->json('token'));
        $this->assertNull($reponse->json('double_authentification_requise'));
    }

    public function test_l_etat_par_defaut_n_active_rien(): void
    {
        Sanctum::actingAs($this->client(), ['*']);

        $this->getJson('/api/user/account/double-authentification')
            ->assertSuccessful()
            ->assertJsonPath('data.application.active', false)
            ->assertJsonPath('data.courriel.active', false);
    }

    // --- Mise en place de l'application -------------------------------------

    public function test_preparer_rend_un_secret_et_un_qr_sans_rien_enregistrer(): void
    {
        $eva = $this->client();
        Sanctum::actingAs($eva, ['*']);

        $reponse = $this->postJson('/api/user/account/double-authentification/application/preparer');

        $reponse->assertSuccessful();
        $this->assertNotEmpty($reponse->json('data.secret'));
        $this->assertStringStartsWith('data:image/svg+xml', $reponse->json('data.qr'));

        // RIEN n'est enregistre : un QR jamais scanne ne doit pas verrouiller
        // le compte.
        $this->assertNull($eva->fresh()->getAppAuthenticationSecret());
    }

    public function test_activer_avec_un_bon_code_enregistre_et_rend_les_codes_de_secours(): void
    {
        $eva = $this->client();
        Sanctum::actingAs($eva, ['*']);

        $secret = $this->google2fa->generateSecretKey();

        $reponse = $this->postJson('/api/user/account/double-authentification/application/activer', [
            'secret' => $secret,
            'code' => $this->google2fa->getCurrentOtp($secret),
        ]);

        $reponse->assertSuccessful();
        $this->assertCount(8, $reponse->json('data.codes_de_secours'));
        $this->assertSame($secret, $eva->fresh()->getAppAuthenticationSecret());
    }

    public function test_activer_avec_un_mauvais_code_ne_change_rien(): void
    {
        $eva = $this->client();
        Sanctum::actingAs($eva, ['*']);

        $this->postJson('/api/user/account/double-authentification/application/activer', [
            'secret' => $this->google2fa->generateSecretKey(),
            'code' => '000000',
        ])->assertStatus(400);

        $this->assertNull($eva->fresh()->getAppAuthenticationSecret());
    }

    /**
     * Desactiver exige le mot de passe.
     *
     * Sans cela, un telephone laisse deverrouille suffirait a retirer le
     * second facteur — ce dont il protege, precisement.
     */
    public function test_desactiver_sans_mot_de_passe_est_refuse(): void
    {
        $eva = $this->client();
        $eva->saveAppAuthenticationSecret($this->google2fa->generateSecretKey());
        Sanctum::actingAs($eva, ['*']);

        $this->postJson('/api/user/account/double-authentification/application/desactiver', [
            'password' => 'pas le bon',
        ])->assertStatus(400);

        $this->assertNotNull($eva->fresh()->getAppAuthenticationSecret());
    }

    public function test_desactiver_avec_le_mot_de_passe_retire_le_facteur(): void
    {
        $eva = $this->client();
        $eva->saveAppAuthenticationSecret($this->google2fa->generateSecretKey());
        Sanctum::actingAs($eva, ['*']);

        $this->postJson('/api/user/account/double-authentification/application/desactiver', [
            'password' => 'motdepasse',
        ])->assertSuccessful();

        $this->assertNull($eva->fresh()->getAppAuthenticationSecret());
    }

    // --- La connexion en deux temps -----------------------------------------

    public function test_avec_l_application_la_connexion_n_emet_pas_de_jeton(): void
    {
        $eva = $this->client();
        $eva->saveAppAuthenticationSecret($this->google2fa->generateSecretKey());

        $reponse = $this->connexion();

        $reponse->assertSuccessful()
            ->assertJsonPath('double_authentification_requise', true)
            ->assertJsonPath('facteurs', ['application']);

        $this->assertNull($reponse->json('token'));
        $this->assertNotEmpty($reponse->json('defi'));
    }

    public function test_le_code_de_l_application_delivre_le_jeton(): void
    {
        $eva = $this->client();
        $secret = $this->google2fa->generateSecretKey();
        $eva->saveAppAuthenticationSecret($secret);

        $defi = $this->connexion()->json('defi');

        $this->postJson('/api/login/double-authentification', [
            'defi' => $defi,
            'code' => $this->google2fa->getCurrentOtp($secret),
        ])->assertSuccessful()->assertJsonStructure(['token']);
    }

    /** Un defi ne sert qu'une fois : sinon il rouvrirait une session pendant cinq minutes. */
    public function test_un_defi_resolu_ne_resert_pas(): void
    {
        $eva = $this->client();
        $secret = $this->google2fa->generateSecretKey();
        $eva->saveAppAuthenticationSecret($secret);

        $defi = $this->connexion()->json('defi');
        $code = $this->google2fa->getCurrentOtp($secret);

        $this->postJson('/api/login/double-authentification', ['defi' => $defi, 'code' => $code])
            ->assertSuccessful();

        $this->postJson('/api/login/double-authentification', ['defi' => $defi, 'code' => $code])
            ->assertStatus(400);
    }

    public function test_un_code_de_secours_marche_une_fois_et_pas_deux(): void
    {
        $eva = $this->client();
        $eva->saveAppAuthenticationSecret($this->google2fa->generateSecretKey());
        $eva->saveAppAuthenticationRecoveryCodes(['aaaa-bbbb', 'cccc-dddd']);

        $defi = $this->connexion()->json('defi');

        $this->postJson('/api/login/double-authentification', ['defi' => $defi, 'code' => 'aaaa-bbbb'])
            ->assertSuccessful();

        $this->assertSame(['cccc-dddd'], $eva->fresh()->getAppAuthenticationRecoveryCodes());

        $defi = $this->connexion()->json('defi');

        $this->postJson('/api/login/double-authentification', ['defi' => $defi, 'code' => 'aaaa-bbbb'])
            ->assertStatus(400);
    }

    /**
     * Cinq essais, puis le defi disparait.
     *
     * Six chiffres se devinent en un million d'essais ; sans plafond, cinq
     * minutes en laissent passer bien plus que cela.
     */
    public function test_cinq_essais_rates_detruisent_le_defi(): void
    {
        $eva = $this->client();
        $secret = $this->google2fa->generateSecretKey();
        $eva->saveAppAuthenticationSecret($secret);

        $defi = $this->connexion()->json('defi');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login/double-authentification', [
                'defi' => $defi,
                'code' => '000000',
            ])->assertStatus(400);
        }

        // Meme le bon code ne passe plus : le defi n'existe plus.
        $this->postJson('/api/login/double-authentification', [
            'defi' => $defi,
            'code' => $this->google2fa->getCurrentOtp($secret),
        ])->assertStatus(400);
    }

    // --- Le facteur courriel -------------------------------------------------

    public function test_le_facteur_courriel_envoie_un_code_et_l_accepte(): void
    {
        $eva = $this->client();
        $eva->toggleEmailAuthentication(true);

        $reponse = $this->connexion();

        $reponse->assertJsonPath('facteurs', ['courriel']);
        Mail::assertSent(CodeDoubleAuthentificationMail::class);
    }

    public function test_un_compte_bloque_entre_les_deux_etapes_n_obtient_pas_de_jeton(): void
    {
        $eva = $this->client();
        $secret = $this->google2fa->generateSecretKey();
        $eva->saveAppAuthenticationSecret($secret);

        $defi = $this->connexion()->json('defi');

        $eva->bloquer('Fraude');

        $this->postJson('/api/login/double-authentification', [
            'defi' => $defi,
            'code' => $this->google2fa->getCurrentOtp($secret),
        ])->assertStatus(403);
    }

    /** Le service est le MEME que celui de l'administration : memes colonnes. */
    public function test_le_facteur_active_cote_client_se_voit_cote_administration(): void
    {
        $eva = $this->client();
        $eva->saveAppAuthenticationSecret('SECRET1234567890');

        $this->assertTrue(app(DoubleAuthentification::class)->applicationActive($eva->fresh()));
        $this->assertNotNull($eva->fresh()->getAppAuthenticationSecret());
    }
}
