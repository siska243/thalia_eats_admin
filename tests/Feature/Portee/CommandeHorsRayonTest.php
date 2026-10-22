<?php

namespace Tests\Feature\Portee;

use App\Models\Commande;
use App\Models\CommandeProduct;
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
            'use_client_position' => false,
            'city_first' => false,
            'stock_actif' => true,
            'montant_minimum_commande' => 0.0,
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

    /**
     * Un panier en attente, pose directement en base.
     *
     * On ne passe pas par `/commande/add-product` : cet endpoint n'enregistre
     * pas la commande avant d'ecrire ses lignes, la premiere ligne d'un
     * panier neuf part donc avec `commande_id` a null (defaut preexistant,
     * signale au proprietaire). Le sujet de ce test est le CHANGEMENT
     * d'adresse, pas la construction du panier : on lui donne un etat de
     * depart correct.
     */
    private function panierEnAttente(Product $produit): Commande
    {
        $commande = new Commande;
        $commande->user_id = $this->client->id;
        $commande->refernce = 1000;
        $commande->status_id = 1;
        $commande->town_id = $this->livraison->id;
        $commande->save();

        $ligne = new CommandeProduct;
        $ligne->commande_id = $commande->id;
        $ligne->user_id = $this->client->id;
        $ligne->product_id = $produit->id;
        $ligne->price = $produit->price;
        $ligne->quantity = 1;
        $ligne->currency_id = $produit->currency_id;
        $ligne->save();

        return $commande;
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

    /**
     * Changer l'adresse d'une commande repasse par le garde.
     *
     * `update-address-delivery` est le seul autre ecrivain de `town_id`,
     * `lat` et `long` sur une commande. L'argent reste protege — `valide()`
     * rejoue le controle sur l'adresse qu'il persiste — mais le client
     * decouvrait alors le refus au moment de payer, au lieu du moment ou il
     * choisit son adresse. Et un garde par chemin plutot qu'au passage
     * oblige est exactement ce qui a deja produit deux bugs sur ce depot.
     */
    public function test_changer_l_adresse_pour_une_commune_hors_rayon_est_refuse(): void
    {
        $this->panierEnAttente($this->produitDe(self::GOMBE));

        $lubumbashi = Town::factory()->create([
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);

        $this->postJson('/api/user/commande/update-address-delivery', [
            'town' => $lubumbashi->slug,
            'adresse' => 'Avenue Lumumba',
            'street' => 'Lumumba',
            'number_street' => '5',
        ])
            ->assertStatus(400)
            ->assertJsonPath('title', 'Hors zone de livraison');
    }

    /** Changer pour une adresse a portee reste possible. */
    public function test_changer_l_adresse_dans_le_rayon_reste_possible(): void
    {
        $this->panierEnAttente($this->produitDe(self::GOMBE));

        $reponse = $this->postJson('/api/user/commande/update-address-delivery', [
            'town' => $this->livraison->slug,
            'adresse' => 'Avenue du Commerce',
            'street' => 'Commerce',
            'number_street' => '14',
        ]);

        $this->assertNotSame('Hors zone de livraison', $reponse->json('title'));
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
