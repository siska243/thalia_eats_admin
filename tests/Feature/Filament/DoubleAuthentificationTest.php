<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La double authentification, au choix de chacun.
 *
 * Le proprietaire : « tu ajoutes la possibilite d'activer la double
 * authentification », puis « totp ou courriel tu peux mettre les 2, c'est a
 * l'utilisateur de choisir ».
 *
 * Ce qui est verifie ici tient en trois points, et le deuxieme compte autant
 * que les autres : les deux facteurs sont proposes, le secret ne dort pas en
 * clair dans la base, et personne n'est enrole de force.
 */
class DoubleAuthentificationTest extends TestCase
{
    use RefreshDatabase;

    private function panneau(): Panel
    {
        return Filament::getPanel('admin');
    }

    public function test_les_deux_facteurs_sont_proposes(): void
    {
        $fournisseurs = collect($this->panneau()->getMultiFactorAuthenticationProviders())
            ->map(fn ($fournisseur): string => $fournisseur::class)
            ->values()
            ->all();

        $this->assertContains(AppAuthentication::class, $fournisseurs);
        $this->assertContains(EmailAuthentication::class, $fournisseurs);
    }

    /** Personne n'est enrole d'office : la connexion des autres ne bouge pas. */
    public function test_aucun_facteur_n_est_impose(): void
    {
        $this->assertFalse($this->panneau()->isMultiFactorAuthenticationRequired());
    }

    /**
     * LE SECRET NE DORT PAS EN CLAIR.
     *
     * Sans chiffrement, une fuite de la table livrerait le second facteur en
     * meme temps que le premier, et la mesure n'aurait plus aucun sens.
     */
    public function test_le_secret_totp_est_chiffre_en_base(): void
    {
        $eva = User::factory()->create();

        $eva->saveAppAuthenticationSecret('SECRETENCLAIR1234');

        $brut = DB::table('users')->where('id', $eva->id)->value('app_authentication_secret');

        $this->assertNotSame('SECRETENCLAIR1234', $brut);
        $this->assertStringNotContainsString('SECRETENCLAIR1234', (string) $brut);
        $this->assertSame('SECRETENCLAIR1234', $eva->fresh()->getAppAuthenticationSecret());
    }

    public function test_les_codes_de_secours_sont_chiffres_et_relus(): void
    {
        $eva = User::factory()->create();

        $eva->saveAppAuthenticationRecoveryCodes(['aaaa-bbbb', 'cccc-dddd']);

        $brut = DB::table('users')->where('id', $eva->id)->value('app_authentication_recovery_codes');

        $this->assertStringNotContainsString('aaaa-bbbb', (string) $brut);
        $this->assertSame(['aaaa-bbbb', 'cccc-dddd'], $eva->fresh()->getAppAuthenticationRecoveryCodes());
    }

    public function test_le_facteur_courriel_s_allume_et_s_eteint(): void
    {
        $eva = User::factory()->create();

        $this->assertFalse($eva->hasEmailAuthentication());

        $eva->toggleEmailAuthentication(true);
        $this->assertTrue($eva->fresh()->hasEmailAuthentication());

        $eva->toggleEmailAuthentication(false);
        $this->assertFalse($eva->fresh()->hasEmailAuthentication());
    }

    /** Le second facteur ne sort jamais dans une reponse d'API. */
    public function test_le_secret_n_est_pas_serialise(): void
    {
        $eva = User::factory()->create();
        $eva->saveAppAuthenticationSecret('SECRETENCLAIR1234');

        $json = $eva->fresh()->toArray();

        $this->assertArrayNotHasKey('app_authentication_secret', $json);
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $json);
    }
}
