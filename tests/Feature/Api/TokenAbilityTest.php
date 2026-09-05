<?php

namespace Tests\Feature\Api;

use App\Enums\TokenAbility;
use App\Models\Town;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TokenAbilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_jeton_etoile_conserve_tous_ses_pouvoirs(): void
    {
        // Les 82 jetons de production portent ["*"] : ils ne doivent rien perdre.
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/products/search?q=poulet')->assertStatus(200);
    }

    public function test_un_jeton_agent_peut_chercher_dans_le_catalogue(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        $this->getJson('/api/products/search?q=poulet')->assertStatus(200);
    }

    public function test_un_jeton_sans_l_ability_catalogue_est_refuse(): void
    {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::CommandeLire->value]);

        $this->getJson('/api/products/search?q=poulet')->assertStatus(403);
    }

    public function test_un_jeton_sans_l_ability_devis_ne_peut_pas_chiffrer(): void
    {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::CatalogueLire->value]);
        $town = Town::factory()->create();

        $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => [['uid' => 'peu-importe', 'quantity' => 1]],
        ])->assertStatus(403);
    }

    public function test_l_enum_expose_exactement_cinq_abilities(): void
    {
        $this->assertCount(5, TokenAbility::agent());
        $this->assertContains('precommande:creer', TokenAbility::agent());
        $this->assertNotContains('commande:annuler', TokenAbility::agent());
    }
}
