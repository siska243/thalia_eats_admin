<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Se connecter avec son numéro, et ne pas pouvoir l'inscrire deux fois.
 *
 * Le propriétaire, le 28 octobre : « tu mets en multi step, par défaut tu
 * mets par email, ensuite un bouton se connecter avec mon téléphone », puis
 * « lors de l'enregistrement on doit rendre ça unique, pas dans la base mais
 * dans le register » et « on supprime rien, on va décider plus tard ».
 *
 * Les trois contraintes tiennent ensemble ici. L'unicité est posée à
 * l'inscription et non par un index, parce que onze comptes partagent déjà
 * cinq numéros et qu'on les garde. La connexion doit donc vivre avec ces
 * doublons sans jamais ouvrir le mauvais compte : c'est le mot de passe qui
 * départage, et un numéro partagé dont aucun mot de passe ne correspond rend
 * exactement le même refus qu'un numéro inconnu.
 *
 * Le format ne compte pas : « 0821693466 » et « +243 821 693 466 » sont le
 * même abonné des deux côtés, parce que les deux chemins lisent la même
 * définition, `User::comptesAvecTelephone()`.
 */
class ConnexionParTelephoneTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $attributs = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make('motdepasse'),
        ], $attributs));
    }

    public function test_on_se_connecte_avec_son_numero(): void
    {
        $this->client(['email' => 'eva@example.com', 'phone' => '+243821693466']);

        $this->postJson('/api/login', [
            'phone' => '+243821693466',
            'password' => 'motdepasse',
        ])->assertSuccessful();
    }

    public function test_le_format_du_numero_est_indifferent(): void
    {
        // Enregistre en international avec des espaces, saisi en national.
        $this->client(['email' => 'eva@example.com', 'phone' => '+243 821 693 466']);

        $this->postJson('/api/login', [
            'phone' => '0821693466',
            'password' => 'motdepasse',
        ])->assertSuccessful();
    }

    public function test_un_mauvais_mot_de_passe_est_refuse(): void
    {
        $this->client(['phone' => '+243821693466']);

        $this->postJson('/api/login', [
            'phone' => '+243821693466',
            'password' => 'pas-le-bon',
        ])->assertStatus(400);
    }

    public function test_un_numero_inconnu_est_refuse(): void
    {
        $this->postJson('/api/login', [
            'phone' => '+243810000001',
            'password' => 'motdepasse',
        ])->assertStatus(400);
    }

    /**
     * Le cas qui motive tout le detour : deux comptes, un seul numero.
     * Prendre le premier venu ferait entrer Eva chez Jonas.
     */
    public function test_un_numero_partage_ouvre_le_compte_dont_le_mot_de_passe_correspond(): void
    {
        $this->client([
            'email' => 'eva@example.com',
            'phone' => '+243821693466',
            'password' => Hash::make('celui-d-eva'),
        ]);

        $this->client([
            'email' => 'jonas@example.com',
            'phone' => '+243 821 693 466',
            'password' => Hash::make('celui-de-jonas'),
        ]);

        $reponse = $this->postJson('/api/login', [
            'phone' => '0821693466',
            'password' => 'celui-d-eva',
        ]);

        $reponse->assertSuccessful();

        // UserResource n'expose pas l'identifiant : l'adresse suffit a dire
        // lequel des deux comptes a ete ouvert, et c'est tout l'enjeu.
        $this->assertSame('eva@example.com', $reponse->json('user.email'));
    }

    public function test_un_numero_partage_sans_mot_de_passe_correspondant_est_refuse_comme_un_inconnu(): void
    {
        $this->client(['phone' => '+243821693466', 'password' => Hash::make('celui-d-eva')]);
        $this->client(['phone' => '+243 821 693 466', 'password' => Hash::make('celui-de-jonas')]);

        $this->postJson('/api/login', [
            'phone' => '0821693466',
            'password' => 'ni-l-un-ni-l-autre',
        ])->assertStatus(400);
    }

    public function test_la_connexion_par_email_ne_change_pas(): void
    {
        $this->client(['email' => 'eva@example.com']);

        $this->postJson('/api/login', [
            'email' => 'eva@example.com',
            'password' => 'motdepasse',
        ])->assertSuccessful();
    }

    public function test_l_inscription_refuse_un_numero_deja_pris(): void
    {
        Role::findOrCreate('client', 'web');

        $this->client(['phone' => '+243 821 693 466']);

        $reponse = $this->postJson('/api/register', [
            'name' => 'Mulumba',
            'last_name' => 'Eva',
            'email' => 'nouvelle@example.com',
            'password' => 'motdepasse',
            'confirm_password' => 'motdepasse',
            'phone' => '0821693466',
        ]);

        $reponse->assertStatus(400);
        $this->assertDatabaseMissing('users', ['email' => 'nouvelle@example.com']);
    }

    public function test_l_inscription_accepte_un_numero_libre(): void
    {
        Role::findOrCreate('client', 'web');

        $this->postJson('/api/register', [
            'name' => 'Mulumba',
            'last_name' => 'Eva',
            'email' => 'nouvelle@example.com',
            'password' => 'motdepasse',
            'confirm_password' => 'motdepasse',
            'phone' => '+243810000001',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'nouvelle@example.com']);
    }
}
