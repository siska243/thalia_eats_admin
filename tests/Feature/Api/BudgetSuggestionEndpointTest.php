<?php

namespace Tests\Feature\Api;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BudgetSuggestionEndpointTest extends TestCase
{
    // Même raison qu'au-dessus : ce endpoint peut emprunter le chemin FULLTEXT
    // dès qu'un `q` est fourni.
    use DatabaseTruncation;

    /**
     * DatabaseTruncation tronque en setUp(), pas en tearDown() : sans ceci, les
     * lignes committées par le dernier test de cette classe survivraient et
     * seraient visibles par une classe RefreshDatabase exécutée ensuite.
     */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    private function contexte(): array
    {
        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        $restaurant = Restaurant::factory()->create(['town_id' => $town->id]);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 2000, 'service_price' => 500,
        ]);

        return [$town, $currency, $restaurant];
    }

    public function test_l_endpoint_exige_une_authentification(): void
    {
        $this->postJson('/api/budget-suggestions', [])->assertStatus(401);
    }

    public function test_il_propose_ce_qui_tient_dans_le_budget(): void
    {
        // ['*'] modelise un client applicatif : createToken() sans arguments
        // accorde cette ability, et c'est ce que portent les jetons du web et du
        // mobile en production. Sans elle, Sanctum::actingAs cree un jeton SANS
        // aucune ability, qui ne modelise aucun client reel.
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $restaurant] = $this->contexte();

        Product::factory()->create([
            'title' => 'Beignets', 'price' => 2000,
            'restaurant_id' => $restaurant->id, 'currency_id' => $currency->id,
        ]);

        $response = $this->postJson('/api/budget-suggestions', [
            'budget' => 5000,
            'currency' => $currency->slug,
            'town' => $town->slug,
        ]);

        $response->assertStatus(200)->assertJson([
            'disponible' => true,
            'raison' => null,
        ]);

        $this->assertCount(1, $response->json('suggestions'));
        $this->assertSame(4500, $response->json('suggestions.0.total'));
    }

    public function test_quand_rien_ne_rentre_il_dit_combien_il_manque(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $restaurant] = $this->contexte();

        Product::factory()->create([
            'title' => 'Brochette', 'price' => 4000,
            'restaurant_id' => $restaurant->id, 'currency_id' => $currency->id,
        ]);

        $response = $this->postJson('/api/budget-suggestions', [
            'budget' => 5000,
            'currency' => $currency->slug,
            'town' => $town->slug,
        ]);

        $response->assertStatus(200)->assertJson([
            'disponible' => false,
            'raison' => 'budget_insuffisant',
            'option_la_moins_chere' => [
                'total' => 6500,
                'manque' => 1500,
            ],
        ]);
    }

    public function test_un_budget_negatif_est_rejete(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency] = $this->contexte();

        $this->postJson('/api/budget-suggestions', [
            'budget' => -10,
            'currency' => $currency->slug,
            'town' => $town->slug,
        ])->assertStatus(422);
    }

    public function test_une_devise_inconnue_renvoie_404(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town] = $this->contexte();

        $this->postJson('/api/budget-suggestions', [
            'budget' => 5000,
            'currency' => 'devise-inexistante',
            'town' => $town->slug,
        ])->assertStatus(404);
    }
}
