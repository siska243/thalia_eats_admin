<?php

namespace Tests\Feature\Api;

use App\Enums\TokenAbility;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_endpoint_exige_une_authentification(): void
    {
        $this->postJson('/api/user/assistants', ['name' => 'mon Claude'])->assertStatus(401);
    }

    public function test_il_emet_un_jeton_affiche_une_seule_fois(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/user/assistants', ['name' => 'mon Claude']);

        $response->assertStatus(201);
        $this->assertNotEmpty($response->json('data.token'));

        // Le jeton en clair ne doit jamais reapparaitre dans la liste.
        $liste = $this->getJson('/api/user/assistants');
        $liste->assertStatus(200);
        $this->assertSame('mon Claude', $liste->json('0.name'));
        $this->assertArrayNotHasKey('token', $liste->json('0'));
    }

    public function test_le_jeton_emis_porte_les_abilities_agent_et_une_expiration(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/user/assistants', ['name' => 'mon Claude'])->assertStatus(201);

        $token = $user->tokens()->where('name', 'mon Claude')->first();

        $this->assertSame(TokenAbility::agent(), $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->greaterThan(now()->addDays(89)));
    }

    public function test_un_jeton_agent_ne_peut_pas_en_emettre_un_autre(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        $this->postJson('/api/user/assistants', ['name' => 'un autre'])->assertStatus(403);
        $this->getJson('/api/user/assistants')->assertStatus(403);
    }

    public function test_il_revoque_un_jeton(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/user/assistants', ['name' => 'mon Claude'])->assertStatus(201);
        $token = $user->tokens()->where('name', 'mon Claude')->first();

        $this->deleteJson('/api/user/assistants/'.Cipher::Encrypt($token->id))->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_on_ne_revoque_pas_le_jeton_d_un_autre(): void
    {
        $victime = User::factory()->create();
        $token = $victime->createToken('sa connexion', TokenAbility::agent());

        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->deleteJson('/api/user/assistants/'.Cipher::Encrypt($token->accessToken->id))
            ->assertStatus(404);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_un_nom_est_obligatoire(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/user/assistants', [])->assertStatus(422);
    }
}
