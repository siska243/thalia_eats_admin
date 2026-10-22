<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Se deconnecter ferme UNE session, pas toutes.
 *
 * Deux routes de deconnexion coexistent, et elles ne font pas la meme chose :
 *
 *   api/logout       la notre, `Api\AuthController@logout` : supprime le seul
 *                    jeton courant.
 *   api/auth/logout  celle du paquet rupadana/filament-api-service :
 *                    `Auth::user()->tokens()->delete()`, donc TOUS.
 *
 * Le web appelait la seconde. Se deconnecter du site fermait aussi la session
 * du telephone, sur un autre appareil, sans que personne ne l'ait demande. Il
 * appelle desormais la notre, et ce test garde la promesse sur laquelle il
 * s'appuie : un appareil deconnecte n'en deconnecte pas un autre.
 */
class DeconnexionTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_deconnexion_ne_revoque_que_le_jeton_courant(): void
    {
        $client = User::factory()->create(['email_verified_at' => now()]);

        // Deux appareils, deux jetons. Le telephone ne doit rien voir passer.
        $telephone = $client->createToken('telephone')->plainTextToken;
        $navigateur = $client->createToken('navigateur')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$navigateur)
            ->postJson('/api/logout')
            ->assertSuccessful();

        $this->assertSame(1, $client->tokens()->count());

        $this->app['auth']->forgetGuards();

        // Et le jeton du telephone ouvre toujours.
        $this->withHeader('Authorization', 'Bearer '.$telephone)
            ->getJson('/api/user/account')
            ->assertSuccessful();
    }

    public function test_le_jeton_utilise_pour_se_deconnecter_ne_sert_plus(): void
    {
        $client = User::factory()->create(['email_verified_at' => now()]);
        $jeton = $client->createToken('navigateur')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$jeton)
            ->postJson('/api/logout')
            ->assertSuccessful();

        /*
         * En production chaque requete repart d'un conteneur neuf. Dans un
         * test, les deux requetes partagent la meme application, et le garde
         * garde l'utilisateur deja resolu : sans cet oubli, le jeton
         * supprime continue d'ouvrir, et le test mesurerait le cache au lieu
         * de la revocation.
         */
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$jeton)
            ->getJson('/api/user/account')
            ->assertUnauthorized();
    }

    /** Sans jeton, personne ne se deconnecte : la route est sous `auth:sanctum`. */
    public function test_la_deconnexion_exige_une_session(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }
}
