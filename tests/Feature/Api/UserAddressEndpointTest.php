<?php

namespace Tests\Feature\Api;

use App\Models\Town;
use App\Models\User;
use App\Models\UserAdresse;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La table user_adresses existait depuis l'origine sans etre alimentee :
 * update_adresse ecrit dans la table users, si bien qu'un client n'avait
 * qu'une seule adresse, ecrasee a chaque modification.
 */
class UserAddressEndpointTest extends TestCase
{
    use DatabaseTruncation;

    public function test_l_endpoint_exige_une_authentification(): void
    {
        $this->getJson('/api/user/addresses')->assertStatus(401);
    }

    public function test_une_adresse_est_enregistree_avec_ses_coordonnees(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $town = Town::factory()->create();

        $response = $this->postJson('/api/user/addresses', [
            'adresse' => 'Avenue du Port 12',
            'town' => $town->slug,
            'label' => 'Maison',
            'lat' => -4.325,
            'long' => 15.322,
        ]);

        $response->assertStatus(201);
        $this->assertSame('Maison', $response->json('data.label'));
        $this->assertSame(-4.325, $response->json('data.lat'));
    }

    public function test_la_meme_adresse_n_est_pas_dupliquee(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $town = Town::factory()->create();

        $payload = ['adresse' => 'Avenue du Port 12', 'town' => $town->slug];

        $this->postJson('/api/user/addresses', $payload)->assertStatus(201);
        $this->postJson('/api/user/addresses', $payload)->assertStatus(201);

        $this->assertSame(1, UserAdresse::where('user_id', $user->id)->count());
    }

    public function test_une_seule_adresse_principale_a_la_fois(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $town = Town::factory()->create();

        $this->postJson('/api/user/addresses', [
            'adresse' => 'Avenue du Port 12',
            'town' => $town->slug,
            'is_main' => true,
        ])->assertStatus(201);

        $this->postJson('/api/user/addresses', [
            'adresse' => 'Boulevard du 30 Juin 5',
            'town' => $town->slug,
            'is_main' => true,
        ])->assertStatus(201);

        $this->assertSame(1, UserAdresse::where('user_id', $user->id)->where('is_main', true)->count());
    }

    public function test_on_ne_voit_pas_les_adresses_d_un_autre_client(): void
    {
        $town = Town::factory()->create();
        $autre = User::factory()->create();

        UserAdresse::create([
            'user_id' => $autre->id,
            'adresse' => 'Adresse du voisin',
            'town_id' => $town->id,
        ]);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/user/addresses');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_une_adresse_se_supprime(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $town = Town::factory()->create();

        $address = UserAdresse::create([
            'user_id' => $user->id,
            'adresse' => 'Avenue du Port 12',
            'town_id' => $town->id,
        ]);

        $this->deleteJson("/api/user/addresses/{$address->slug}")->assertStatus(200);

        $this->assertSame(0, UserAdresse::where('user_id', $user->id)->count());
    }

    public function test_une_commune_inconnue_est_refusee(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/user/addresses', [
            'adresse' => 'Avenue du Port 12',
            'town' => 'commune-inexistante',
        ])->assertStatus(400);
    }
}
