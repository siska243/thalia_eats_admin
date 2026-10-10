<?php

namespace Tests\Feature\Api;

use App\Enums\TokenAbility;
use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Precommande;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Un assistant cree sans effort : une boucle maladroite, et le client recoit
 * quarante liens de paiement. Chaque pre-commande fige aussi un prix pendant
 * douze heures, donc chacune engage Thalia commercialement.
 *
 * Le plafond compte les pre-commandes ACTIVES, jamais le total historique :
 * il se libere tout seul, par paiement ou par expiration.
 */
class PlafondPrecommandesTest extends TestCase
{
    use RefreshDatabase;

    private Town $town;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->town = Town::factory()->create();
        $currency = Currency::factory()->create();
        $restaurant = Restaurant::factory()->create(['town_id' => $this->town->id]);

        DelivreryPrice::factory()->create([
            'town_id' => $this->town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 2000, 'service_price' => 500,
        ]);

        $this->product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $currency->id,
            'price' => 1500,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'town' => $this->town->slug,
            'products' => [['uid' => Cipher::Encrypt($this->product->id), 'quantity' => 1]],
        ];
    }

    private function agent(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, TokenAbility::agent());

        return $user;
    }

    public function test_le_plafond_par_defaut_est_de_huit(): void
    {
        $this->assertSame(8, (int) config('precommande.plafond_actives'));
    }

    public function test_la_huitieme_passe_et_la_neuvieme_est_refusee(): void
    {
        $user = $this->agent();

        Precommande::factory()->count(7)->create(['user_id' => $user->id]);

        // La huitieme : le plafond n'est pas encore atteint.
        $this->postJson('/api/precommandes', $this->payload())->assertStatus(201);

        $response = $this->postJson('/api/precommandes', $this->payload());

        $response->assertStatus(400);
        $response->assertJson(['error' => 'trop_de_precommandes']);
        $this->assertSame(8, Precommande::query()->where('user_id', $user->id)->count());
    }

    public function test_le_message_dit_au_client_quoi_faire(): void
    {
        $user = $this->agent();
        Precommande::factory()->count(8)->create(['user_id' => $user->id]);

        $response = $this->postJson('/api/precommandes', $this->payload());

        // Un refus qui ne dit pas comment en sortir laisse le client bloque.
        $response->assertJsonFragment(['message' => 'Vous avez déjà 8 commandes en attente de paiement. Payez-en une ou attendez qu\'elles expirent avant d\'en créer une autre.']);
    }

    public function test_les_precommandes_expirees_ne_comptent_pas(): void
    {
        $user = $this->agent();
        Precommande::factory()->count(8)->expiree()->create(['user_id' => $user->id]);

        $this->postJson('/api/precommandes', $this->payload())->assertStatus(201);
    }

    public function test_les_precommandes_payees_ne_comptent_pas(): void
    {
        $user = $this->agent();

        Precommande::factory()->count(8)->create([
            'user_id' => $user->id,
            'status' => Precommande::STATUT_PAYEE,
        ]);

        $this->postJson('/api/precommandes', $this->payload())->assertStatus(201);
    }

    public function test_le_plafond_est_par_client_et_non_global(): void
    {
        $voisin = User::factory()->create();
        Precommande::factory()->count(8)->create(['user_id' => $voisin->id]);

        $this->agent();

        $this->postJson('/api/precommandes', $this->payload())->assertStatus(201);
    }

    public function test_le_plafond_suit_la_configuration(): void
    {
        config(['precommande.plafond_actives' => 2]);

        $user = $this->agent();
        Precommande::factory()->count(2)->create(['user_id' => $user->id]);

        $this->postJson('/api/precommandes', $this->payload())->assertStatus(400);
    }
}
