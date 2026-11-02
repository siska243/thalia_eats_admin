<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Status;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Un restaurant desactive ne se voit pas et ne se commande pas.
 *
 * Signale par le proprietaire : « restaurant desactive, le client peut quand
 * meme commander ». La desactivation ne vivait que dans la LISTE : la fiche,
 * la carte et le menu se resolvent par slug, et le reglement ne regardait pas
 * `is_active` du tout. Un lien garde en favori rouvrait donc la vitrine d'un
 * restaurant eteint, et le panier qui en sortait allait jusqu'au paiement.
 *
 * Jumeau de RestaurantFermeTest : ferme est temporaire et annonce une heure
 * de reouverture, desactive ne rouvre pas.
 */
class RestaurantDesactiveTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Restaurant $restaurant;

    private Product $produit;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([[1, 'En attente', 'en-attente'], [5, 'En attente paiement', 'en-attente-paiement']] as [$id, $titre, $slug]) {
            Status::query()->firstOrCreate(['id' => $id], ['title' => $titre, 'slug' => $slug]);
        }

        $this->client = User::factory()->create();
        Sanctum::actingAs($this->client, ['*']);

        $this->restaurant = Restaurant::factory()->inactive()->create();
        $this->produit = Product::factory()->create(['restaurant_id' => $this->restaurant->id]);
    }

    // --- La vitrine ----------------------------------------------------------

    public function test_la_fiche_d_un_restaurant_desactive_est_introuvable(): void
    {
        $this->getJson('/api/list-restaurant/'.$this->restaurant->slug)->assertStatus(404);
    }

    public function test_la_carte_d_un_restaurant_desactive_est_introuvable(): void
    {
        $this->getJson('/api/categorie-restaurant/'.$this->restaurant->slug)->assertStatus(404);
    }

    public function test_le_menu_d_un_restaurant_desactive_est_introuvable(): void
    {
        $this->getJson('/api/menu/'.$this->restaurant->slug.'/entrees')->assertStatus(404);
    }

    /** Actif, rien ne change : la garde ne doit pas fermer la boutique. */
    public function test_la_fiche_d_un_restaurant_actif_reste_servie(): void
    {
        $actif = Restaurant::factory()->create();

        $this->getJson('/api/list-restaurant/'.$actif->slug)->assertSuccessful();
        $this->getJson('/api/categorie-restaurant/'.$actif->slug)->assertSuccessful();
    }

    // --- La commande ---------------------------------------------------------

    public function test_on_n_ajoute_pas_un_plat_d_un_restaurant_desactive(): void
    {
        $this->postJson('/api/user/commande/add-product', [
            'products' => [[
                'product_id' => Cipher::Encrypt((string) $this->produit->id),
                'quantity' => 1,
                'pricing' => $this->produit->currency_id,
            ]],
        ])
            ->assertStatus(400)
            ->assertJsonPath('title', 'Restaurant indisponible');

        $this->assertSame(0, CommandeProduct::query()->where('user_id', $this->client->id)->count());
    }

    /**
     * Le cas qui comptait : le panier a ete rempli pendant que le restaurant
     * tournait encore, et l'administration l'a eteint depuis. Rien ne part au
     * paiement.
     *
     * Le titre du refus n'est pas verifie ici : le controle du panier deja
     * ouvert passe avant celui du restaurant sur cette route, comme dans
     * RestaurantFermeTest.
     */
    public function test_le_reglement_est_refuse_apres_la_desactivation(): void
    {
        $this->restaurant->update(['is_active' => true]);

        $this->postJson('/api/user/commande/add-product', [
            'products' => [[
                'product_id' => Cipher::Encrypt((string) $this->produit->id),
                'quantity' => 1,
                'pricing' => $this->produit->currency_id,
            ]],
        ])->assertSuccessful();

        $this->restaurant->update(['is_active' => false]);

        $this->postJson('/api/user/commande/valide', [
            'products' => [['uid' => Cipher::Encrypt((string) $this->produit->id), 'quantity' => 1]],
            'pricing' => ['frais_livraison' => 1, 'service_price' => 1],
            'total_price' => 12,
            'method' => 'mobile',
            'phone' => '+243810000001',
            'mobile' => '+243810000001',
            'address' => ['adresse' => 'Avenue du Commerce', 'street' => 'Commerce', 'number_street' => '12', 'town' => ['slug' => 'gombe']],
        ])->assertStatus(400);

        $this->assertSame(
            0,
            Commande::query()
                ->where('user_id', $this->client->id)
                ->where('status_id', Commande::STATUT_ATTENTE_PAIEMENT)
                ->count(),
        );
    }
}
