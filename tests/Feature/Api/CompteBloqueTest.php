<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Bloquer un compte depuis l'administration.
 *
 * Le proprietaire, le 17 octobre : « dans l'admin avoir la possibilite
 * d'activer (marque comme valide) un compte / bloquer un compte egalement ».
 *
 * Bloquer veut dire couper l'acces, pas poser une colonne que personne ne
 * lit. Deux portes, et les deux comptent : la connexion refuse, et un jeton
 * deja emis cesse d'ouvrir l'API — sinon un compte bloque reste a l'interieur
 * aussi longtemps que son telephone ne se deconnecte pas.
 *
 * La colonne est NOUVELLE et nullable. `is_active` existait, mais vaut faux
 * pour vingt comptes sur vingt-trois et n'est lu nulle part : s'en servir
 * aurait mis presque tout le monde dehors au deploiement.
 */
class CompteBloqueTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $attributs = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make('motdepasse'),
        ], $attributs));
    }

    public function test_un_compte_ordinaire_se_connecte(): void
    {
        $this->client(['email' => 'eva@example.com']);

        $this->postJson('/api/login', [
            'email' => 'eva@example.com',
            'password' => 'motdepasse',
        ])->assertSuccessful();
    }

    public function test_un_compte_bloque_ne_se_connecte_plus(): void
    {
        $this->client(['email' => 'eva@example.com'])->bloquer('Inscriptions automatisees');

        $reponse = $this->postJson('/api/login', [
            'email' => 'eva@example.com',
            'password' => 'motdepasse',
        ]);

        $reponse->assertStatus(403);
        $this->assertStringContainsString('bloqué', $reponse->json('message'));
    }

    public function test_bloquer_revoque_les_jetons_deja_emis(): void
    {
        $eva = $this->client();
        $jeton = $eva->createToken('api token')->plainTextToken;

        $eva->bloquer();

        $this->withHeader('Authorization', 'Bearer '.$jeton)
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    /**
     * La ceinture en plus des bretelles : meme si un jeton survivait a la
     * revocation, le garde de l'API refuse le porteur bloque.
     */
    public function test_un_jeton_survivant_n_ouvre_rien(): void
    {
        $eva = $this->client();
        $jeton = $eva->createToken('api token')->plainTextToken;

        // On bloque SANS passer par bloquer(), donc sans revoquer : c'est le
        // garde seul qu'on met a l'epreuve.
        $eva->forceFill(['blocked_at' => now()])->save();

        $this->withHeader('Authorization', 'Bearer '.$jeton)
            ->getJson('/api/user')
            ->assertStatus(403);
    }

    public function test_debloquer_rend_l_acces(): void
    {
        $eva = $this->client(['email' => 'eva@example.com']);
        $eva->bloquer();
        $eva->debloquer();

        $this->postJson('/api/login', [
            'email' => 'eva@example.com',
            'password' => 'motdepasse',
        ])->assertSuccessful();
    }

    public function test_valider_un_compte_pose_la_date_de_confirmation(): void
    {
        $eva = $this->client(['email_verified_at' => null]);

        $eva->marquerAdresseConfirmee();

        $this->assertNotNull($eva->fresh()->email_verified_at);
    }

    /** Valider deux fois ne repousse pas la date : elle dit quand, pas combien. */
    public function test_valider_deux_fois_garde_la_premiere_date(): void
    {
        $eva = $this->client(['email_verified_at' => now()->subDays(3)]);
        $premiere = $eva->email_verified_at;

        $eva->marquerAdresseConfirmee();

        $this->assertTrue($premiere->equalTo($eva->fresh()->email_verified_at));
    }
}
