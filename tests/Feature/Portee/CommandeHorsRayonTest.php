<?php

namespace Tests\Feature\Portee;

use App\Models\Commande;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use App\Settings\FoodSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le dernier verrou.
 *
 * Meme lecon que `RestaurantFermeTest` : une regle qui ne vit que dans les
 * ecrans ne protege rien. Un appel direct a l'API doit se heurter au meme
 * refus que le bouton grise.
 *
 * Gombe (-4.3045, 15.3075) et Lubumbashi (-11.6600, 27.4800) sont a plus de
 * 1 500 km : aucun rayon raisonnable ne les reunit.
 */
class CommandeHorsRayonTest extends TestCase
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
        ], loadMissingValues: false);

        foreach ([[1, 'En attente', 'en-attente'], [5, 'En attente paiement', 'en-attente-paiement']] as [$id, $titre, $slug]) {
            Status::query()->firstOrCreate(['id' => $id], ['title' => $titre, 'slug' => $slug]);
        }

        $this->currency = Currency::factory()->create();

        $this->livraison = Town::factory()->create([
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);

        $this->client = User::factory()->create();
        Sanctum::actingAs($this->client, ['*']);
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

    /** @return array<string, mixed> L'adresse telle que l'envoient les clients. */
    private function adresse(): array
    {
        return [
            'adresse' => 'Avenue du Commerce',
            'street' => 'Commerce',
            'number_street' => '12',
            'reference' => null,
            'lat' => self::GOMBE[0],
            'long' => self::GOMBE[1],
            'town' => [
                'uid' => Cipher::Encrypt((string) $this->livraison->id),
                'slug' => $this->livraison->slug,
            ],
        ];
    }

    private function creer(Product $produit): TestResponse
    {
        return $this->postJson('/api/user/commande/add', [
            'products' => [['uid' => Cipher::Encrypt((string) $produit->id), 'quantity' => 1]],
            'pricing' => ['frais_livraison' => 1, 'service_price' => 1],
            'adresse' => $this->adresse(),
        ]);
    }

    private function regler(Product $produit): TestResponse
    {
        return $this->postJson('/api/user/commande/valide', [
            'products' => [['uid' => Cipher::Encrypt((string) $produit->id), 'quantity' => 1]],
            'pricing' => ['frais_livraison' => 1, 'service_price' => 1],
            'total_price' => 12,
            'method' => 'mobile',
            'phone' => '+243810000001',
            'mobile' => '+243810000001',
            'address' => $this->adresse(),
        ]);
    }

    public function test_le_reglement_est_refuse_quand_le_restaurant_est_trop_loin(): void
    {
        $this->regler($this->produitDe(self::LUBUMBASHI))
            ->assertStatus(400)
            ->assertJsonPath('title', 'Hors zone de livraison')
            ->assertJsonPath('message', fn (?string $m) => str_contains((string) $m, 'Al-Dar'));

        // Aucune commande n'est partie au paiement.
        $this->assertSame(
            0,
            Commande::query()
                ->where('user_id', $this->client->id)
                ->where('status_id', Commande::STATUT_ATTENTE_PAIEMENT)
                ->count(),
        );
    }

    public function test_la_creation_est_refusee_quand_le_restaurant_est_trop_loin(): void
    {
        $this->creer($this->produitDe(self::LUBUMBASHI))
            ->assertStatus(400)
            ->assertJsonPath('title', 'Hors zone de livraison');

        $this->assertSame(
            0,
            Commande::query()->where('user_id', $this->client->id)->count(),
        );
    }

    /** Dans le rayon, le garde ne doit pas fermer la boutique. */
    public function test_un_restaurant_dans_le_rayon_n_est_pas_refuse_pour_la_distance(): void
    {
        $reponse = $this->creer($this->produitDe(self::GOMBE));

        $this->assertNotSame('Hors zone de livraison', $reponse->json('title'));
    }

    /**
     * Sans position exploitable, aucun refus.
     *
     * Un parc mal renseigne ne doit pas fermer la plateforme : c'est la
     * doctrine de `PorteeLivraison`, et le garde ne doit pas la contredire.
     */
    public function test_un_restaurant_sans_position_n_est_pas_refuse(): void
    {
        $restaurant = Restaurant::factory()->create([
            'town_id' => null, 'location' => null, 'name' => 'Sans GPS',
        ]);
        $produit = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $this->currency->id,
            'price' => 1000.0,
        ]);

        $reponse = $this->creer($produit);

        $this->assertNotSame('Hors zone de livraison', $reponse->json('title'));
    }
}
