<?php

namespace Tests\Feature\Portee;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Precommande;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use App\Settings\FoodSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La pre-commande emprunte le refus du devis.
 *
 * `PrecommandeService::creer()` appelle deja `quote()` et leve des que le
 * devis refuse : la distance y remonte toute seule. Ce test verifie qu'elle
 * arrive au client AVEC sa phrase, et pas sous la forme d'un code brut —
 * c'est un assistant qui lit cette reponse et la repete a un humain.
 */
class PrecommandeHorsRayonTest extends TestCase
{
    use RefreshDatabase;

    private const GOMBE = [-4.3045, 15.3075];

    private const LUBUMBASHI = [-11.6600, 27.4800];

    private User $client;

    private Town $livraison;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
            'use_client_position' => false,
        ], loadMissingValues: false);

        $this->currency = Currency::factory()->create();

        $this->livraison = Town::factory()->create([
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);

        DelivreryPrice::factory()->create([
            'town_id' => $this->livraison->id,
            'currency_id' => $this->currency->id,
        ]);

        $this->client = User::factory()->create();
        Sanctum::actingAs($this->client, ['precommande:creer']);
    }

    private function produitDe(array $position): Product
    {
        $restaurant = Restaurant::factory()
            ->located(...$position)
            ->create(['name' => 'Al-Dar']);

        return Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $this->currency->id,
            'price' => 1000.0,
        ]);
    }

    private function creer(Product $produit): TestResponse
    {
        return $this->postJson('/api/precommandes', [
            'town' => $this->livraison->slug,
            'products' => [['uid' => Cipher::Encrypt((string) $produit->id), 'quantity' => 1]],
        ]);
    }

    public function test_une_precommande_hors_rayon_est_refusee_avec_la_phrase_du_serveur(): void
    {
        $reponse = $this->creer($this->produitDe(self::LUBUMBASHI));

        $reponse->assertStatus(400);
        $this->assertStringContainsString('Al-Dar', (string) $reponse->json('message'));
        $this->assertStringContainsString('Rayon', (string) $reponse->json('message'));

        $this->assertSame(0, Precommande::query()->where('user_id', $this->client->id)->count());
    }

    public function test_une_precommande_dans_le_rayon_est_creee(): void
    {
        $this->creer($this->produitDe(self::GOMBE))->assertSuccessful();

        $this->assertSame(1, Precommande::query()->where('user_id', $this->client->id)->count());
    }
}
