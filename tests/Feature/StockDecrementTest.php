<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Currency;
use App\Models\Precommande;
use App\Models\Product;
use App\Models\Status;
use App\Models\User;
use App\Services\ConversionPrecommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le stock part au PAIEMENT, et une seule fois.
 *
 * Le proprietaire a tranche le moment : « au paiement valide ». C'est le seul
 * instant ou la commande est certaine — un panier abandonne n'immobilise rien,
 * et deux clients ne peuvent pas payer le dernier plat.
 *
 * Une commande devient payee a TROIS endroits (verification demandee par le
 * client, webhook de la passerelle, conversion d'une pre-commande) et le
 * webhook peut arriver deux fois pour une seule transaction. Le retrait vit
 * donc dans une seule methode, et porte sa propre marque en base.
 */
class StockDecrementTest extends TestCase
{
    use RefreshDatabase;

    /** Ni `Commande` ni `CommandeProduct` n'ont de fabrique : on cree a la main, comme les autres tests. */
    private function commandeAvec(Product $produit, int $quantite): Commande
    {
        $commande = Commande::query()->create([
            'user_id' => User::factory()->create()->id,
            'status_id' => Commande::STATUT_ATTENTE_PAIEMENT,
            'refernce' => 'CMD-'.uniqid(),
            'global_price' => 10,
        ]);

        $this->ligne($commande, $produit, $quantite);

        return $commande->fresh();
    }

    private function ligne(Commande $commande, Product $produit, int $quantite): void
    {
        CommandeProduct::query()->create([
            'commande_id' => $commande->id,
            'user_id' => $commande->user_id,
            'product_id' => $produit->id,
            'currency_id' => Currency::query()->firstOrCreate(
                ['code' => 'USD'],
                ['title' => 'Dollar', 'slug' => 'usd']
            )->id,
            'quantity' => $quantite,
            'price' => 10,
        ]);
    }

    /**
     * Le chemin de la pre-commande decremente aussi.
     *
     * C'est le troisieme endroit ou une commande devient payee, et le plus
     * facile a oublier : il ne passe ni par le panier, ni par le webhook de
     * commande. Un test unitaire de la methode ne l'aurait pas vu.
     */
    public function test_une_precommande_convertie_retire_le_stock(): void
    {
        Status::factory()->create(['id' => 2]);

        $produit = Product::factory()->create(['stock_quantity' => 10]);
        $precommande = Precommande::factory()->create(['total' => 5500]);
        $precommande->products()->create(['product_id' => $produit->id, 'quantity' => 2, 'price' => 1500]);

        app(ConversionPrecommande::class)->convertir($precommande->fresh('products'));

        $this->assertSame(8, $produit->fresh()->stock_quantity);
    }

    public function test_le_stock_baisse_de_la_quantite_commandee(): void
    {
        $produit = Product::factory()->create(['stock_quantity' => 5]);

        $this->commandeAvec($produit, 2)->decrementerLesStocks();

        $this->assertSame(3, $produit->fresh()->stock_quantity);
    }

    /**
     * Le cas que la colonne existe pour empecher.
     *
     * Un webhook rejoue retirerait le stock une seconde fois pour une seule
     * transaction.
     */
    public function test_un_second_appel_ne_retire_rien_de_plus(): void
    {
        $produit = Product::factory()->create(['stock_quantity' => 5]);
        $commande = $this->commandeAvec($produit, 2);

        $commande->decrementerLesStocks();
        $commande->decrementerLesStocks();

        $this->assertSame(3, $produit->fresh()->stock_quantity);
    }

    /** Le retrait laisse sa trace, en base et datee. */
    public function test_le_retrait_est_trace(): void
    {
        $produit = Product::factory()->create(['stock_quantity' => 5]);
        $commande = $this->commandeAvec($produit, 1);

        $this->assertNull($commande->stock_decremente_at);

        $commande->decrementerLesStocks();

        $this->assertNotNull($commande->fresh()->stock_decremente_at);
    }

    /** Un plat non suivi n'est jamais touche : il reste a null, pas a zero. */
    public function test_un_plat_sans_suivi_n_est_pas_decremente(): void
    {
        $produit = Product::factory()->create(['stock_quantity' => null]);

        $this->commandeAvec($produit, 2)->decrementerLesStocks();

        $this->assertNull($produit->fresh()->stock_quantity);
    }

    /**
     * Le stock ne passe jamais sous zero.
     *
     * Le refus pose a l'ajout et au reglement devrait l'empecher, mais une
     * quantite negative en base serait ingerable pour l'administration — et
     * les deux refus ne couvrent pas le chemin de la conversion de
     * pre-commande.
     */
    public function test_le_stock_ne_descend_pas_sous_zero(): void
    {
        $produit = Product::factory()->create(['stock_quantity' => 1]);

        $this->commandeAvec($produit, 4)->decrementerLesStocks();

        $this->assertSame(0, $produit->fresh()->stock_quantity);
    }

    /** Plusieurs lignes du meme plat : c'est le total qui part. */
    public function test_deux_lignes_du_meme_plat_se_cumulent(): void
    {
        $produit = Product::factory()->create(['stock_quantity' => 10]);
        $commande = $this->commandeAvec($produit, 2);

        $this->ligne($commande, $produit, 3);

        $commande->fresh()->decrementerLesStocks();

        $this->assertSame(5, $produit->fresh()->stock_quantity);
    }
}
