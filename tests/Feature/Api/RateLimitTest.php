<?php

namespace Tests\Feature\Api;

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('agent-devis');
    }

    public function test_le_limiteur_de_devis_declenche_au_seuil(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        // 20 par minute : la 21e doit etre refusee.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/budget-suggestions', [])->assertStatus(422);
        }

        $this->postJson('/api/budget-suggestions', [])->assertStatus(429);
    }

    public function test_un_assistant_n_entame_pas_le_quota_de_l_utilisateur(): void
    {
        // Note : Sanctum::actingAs() (v4.0.8) mocke le token via Mockery sans
        // jamais definir d'id, donc isset($token->id) est toujours faux quel
        // que soit l'appel : cleDeLimitation() retomberait alors a chaque
        // fois sur le meme "user:<id>" et les deux quotas seraient confondus.
        // Comme prevu par le brief, on emet ici de vrais jetons et on
        // authentifie par en-tete Authorization pour que le token reel (avec
        // son id en base) distingue effectivement les deux quotas.
        $user = User::factory()->create();

        $jetonAssistant = $user->createToken('assistant', TokenAbility::agent())->plainTextToken;
        $jetonApplication = $user->createToken('application', ['*'])->plainTextToken;

        // L'assistant consomme son quota de devis...
        for ($i = 0; $i < 20; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$jetonAssistant)
                ->postJson('/api/budget-suggestions', []);
        }
        $this->withHeader('Authorization', 'Bearer '.$jetonAssistant)
            ->postJson('/api/budget-suggestions', [])->assertStatus(429);

        // Le guard sanctum ('RequestGuard') met en cache l'utilisateur
        // resolu pour la duree du test : sans ce forgetGuards(), la requete
        // suivante reutiliserait le jeton assistant deja resolu au lieu de
        // relire le nouvel en-tete Authorization.
        Auth::forgetGuards();

        // ...et le meme utilisateur, depuis son application, passe toujours.
        $this->withHeader('Authorization', 'Bearer '.$jetonApplication)
            ->postJson('/api/budget-suggestions', [])->assertStatus(422);
    }

    public function test_l_emission_de_jetons_est_severement_limitee(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/user/assistants', ['name' => 'connexion '.$i])->assertStatus(201);
        }

        $this->postJson('/api/user/assistants', ['name' => 'de trop'])->assertStatus(429);
    }
}
