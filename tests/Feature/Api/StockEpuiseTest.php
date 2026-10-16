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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Un plat epuise ne se commande pas.
 *
 * Jumelle de `RestaurantFermeTest`, et pour la meme raison : une regle qui ne
 * vit que dans les ecrans ne protege rien. Le refus est oppose aux deux
 * moments qui comptent — l'ajout au panier, pour ne pas laisser le client
 * remplir ce qu'il ne pourra pas payer, et le REGLEMENT, parce qu'un panier
 * rempli avant la rupture survit a la rupture.
 *
 * Un plat dont le stock n'est pas suivi n'est jamais refuse : c'est la
 * garantie que rien ne change pour le catalogue d'aujourd'hui.
 */
class StockEpuiseTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([[1, 'En attente', 'en-attente'], [5, 'En attente paiement', 'en-attente-paiement']] as [$id, $titre, $slug]) {
            Status::query()->firstOrCreate(['id' => $id], ['title' => $titre, 'slug' => $slug]);
        }

        $this->client = User::factory()->create();
        Sanctum::actingAs($this->client, ['*']);
    }

    private function plat(?int $stock): Product
    {
        // Sans horaires : le restaurant est ouvert en permanence, et c'est bien
        // le stock que ce test interroge, pas la fermeture.
        $restaurant = Restaurant::factory()->create(['openHours' => null]);

        return Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'stock_quantity' => $stock,
        ]);
    }

    private function ajouter(Product $produit, int $quantite = 1): TestResponse
    {
        return $this->postJson('/api/user/commande/add-product', [
            'products' => [[
                'product_id' => Cipher::Encrypt((string) $produit->id),
                'quantity' => $quantite,
                'pricing' => $produit->currency_id,
            ]],
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
            'address' => ['adresse' => 'Avenue du Commerce', 'street' => 'Commerce', 'number_street' => '12', 'town' => ['slug' => 'gombe']],
        ]);
    }

    // --- L'ajout au panier ---------------------------------------------------

    public function test_on_n_ajoute_pas_un_plat_epuise(): void
    {
        $this->ajouter($this->plat(0))
            ->assertStatus(400)
            ->assertJsonPath('title', 'Plat épuisé');

        $this->assertSame(
            0,
            CommandeProduct::query()->where('user_id', $this->client->id)->count(),
        );
    }

    /** Du stock, rien ne change : le garde ne doit pas fermer la boutique. */
    public function test_un_plat_en_stock_s_ajoute_toujours(): void
    {
        $this->ajouter($this->plat(3))->assertSuccessful();

        $this->assertSame(
            1,
            CommandeProduct::query()->where('user_id', $this->client->id)->count(),
        );
    }

    /**
     * Le cas qui garantit la non-regression : stock NON SUIVI.
     *
     * C'est l'etat de tout le catalogue de production. S'il etait refuse, la
     * plateforme fermerait d'un coup au deploiement.
     */
    public function test_un_plat_sans_suivi_de_stock_s_ajoute_toujours(): void
    {
        $this->ajouter($this->plat(null))->assertSuccessful();
    }

    /** On ne commande pas plus que ce qui reste. */
    public function test_on_n_ajoute_pas_plus_que_le_stock_restant(): void
    {
        $this->ajouter($this->plat(2), 3)
            ->assertStatus(400)
            ->assertJsonPath('title', 'Plat épuisé');
    }

    // --- Le reglement --------------------------------------------------------

    /**
     * Le panier survit a la rupture ; le reglement, non.
     *
     * Meme decision que pour la fermeture d'un restaurant : c'est l'heure du
     * REGLEMENT qui compte. Sans ce second garde, un panier rempli pendant
     * qu'il restait du stock se paierait apres la rupture.
     */
    public function test_le_reglement_est_refuse_apres_la_rupture(): void
    {
        /*
         * Pas de panier serveur : l'application mobile garde son panier en
         * local et appelle `valide` directement. `valide` refuse d'ailleurs
         * toute commande non reglee AVANT de regarder les produits, donc un
         * panier serveur masquerait le refus qu'on veut prouver.
         *
         * Le MOTIF est verifie, pas seulement le code : sans la commune
         * « gombe » en base, cet appel echoue de toute façon, et un test qui
         * se contenterait du 400 passerait sans rien prouver. Il l'a fait
         * avant que je le resserre.
         */
        $this->regler($this->plat(0))
            ->assertStatus(400)
            ->assertJsonPath('title', 'Plat épuisé');

        $this->assertSame(
            0,
            Commande::query()
                ->where('user_id', $this->client->id)
                ->where('status_id', Commande::STATUT_ATTENTE_PAIEMENT)
                ->count(),
        );
    }

    // --- Ce que les applications recoivent -----------------------------------

    /** Le client doit VOIR l'epuisement, pas le decouvrir au paiement. */
    public function test_le_catalogue_dit_qu_un_plat_est_epuise(): void
    {
        $produit = $this->plat(0);

        $this->getJson('/api/product/'.$produit->slug)
            ->assertSuccessful()
            ->assertJsonPath('data.epuise', true)
            ->assertJsonPath('data.stock_disponible', 0);
    }

    /** Un plat non suivi ne dit rien du stock, et n'est jamais epuise. */
    public function test_un_plat_sans_suivi_n_annonce_aucun_stock(): void
    {
        $produit = $this->plat(null);

        $this->getJson('/api/product/'.$produit->slug)
            ->assertSuccessful()
            ->assertJsonPath('data.epuise', false)
            ->assertJsonPath('data.stock_disponible', null);
    }
}
