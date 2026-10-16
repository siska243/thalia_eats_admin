<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\SubCategoryProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le stock d'un plat : la donnee et l'ecran qui la saisit.
 *
 * Le proprietaire, le 16 octobre : « le stock est pris en charge que si on
 * l'active depuis l'admin dans chaque produit, cote admin on doit voir la
 * quantite en stock ». Le suivi est donc une decision par plat, et le defaut
 * est de ne rien suivre : un parc de production ne doit pas passer epuise au
 * deploiement.
 */
class StockProduitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn () => true);
        $this->actingAs($admin);
    }

    // --- Le modele -----------------------------------------------------------

    /** Le defaut : aucun suivi, et donc aucun changement pour l'existant. */
    public function test_un_produit_sans_stock_renseigne_n_est_pas_suivi(): void
    {
        $produit = Product::factory()->create();

        $this->assertNull($produit->stock_quantity);
        $this->assertFalse($produit->suitLeStock());
        $this->assertFalse($produit->estEpuise());
    }

    /** Zero n'est pas « non suivi » : c'est epuise. */
    public function test_un_produit_suivi_a_zero_est_epuise(): void
    {
        $produit = Product::factory()->create(['stock_quantity' => 0]);

        $this->assertTrue($produit->suitLeStock());
        $this->assertTrue($produit->estEpuise());
    }

    public function test_un_produit_suivi_avec_du_stock_n_est_pas_epuise(): void
    {
        $produit = Product::factory()->create(['stock_quantity' => 3]);

        $this->assertTrue($produit->suitLeStock());
        $this->assertFalse($produit->estEpuise());
    }

    // --- L'ecran d'administration --------------------------------------------

    /** Le formulaire, rempli comme le ferait un admin. */
    private function champs(array $stock = []): array
    {
        return array_merge([
            'title' => 'Poulet pane',
            'restaurant_id' => Restaurant::factory()->create(['is_active' => true])->id,
            'sub_category_product_id' => SubCategoryProduct::factory()->create(['is_active' => true])->id,
            'currency_id' => Currency::factory()->create(['is_active' => 1])->id,
            'price' => 12,
        ], $stock);
    }

    public function test_l_admin_active_le_suivi_et_saisit_la_quantite(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->champs(['suit_le_stock' => true, 'stock_quantity' => 7]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(7, Product::query()->first()->stock_quantity);
    }

    /**
     * Interrupteur eteint : la quantite retourne a null, pas a zero.
     *
     * Zero fermerait le plat a la vente alors que l'admin vient justement de
     * dire qu'il ne veut plus suivre son stock.
     */
    public function test_eteindre_le_suivi_efface_la_quantite(): void
    {
        $produit = Product::factory()->create(['stock_quantity' => 7]);

        Livewire::test(EditProduct::class, ['record' => $produit->getRouteKey()])
            ->fillForm(['suit_le_stock' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($produit->fresh()->stock_quantity);
    }

    /** Un produit cree sans toucher au stock reste non suivi. */
    public function test_un_produit_cree_sans_toucher_au_stock_reste_non_suivi(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->champs())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Product::query()->first()->stock_quantity);
    }

    /** « Cote admin on doit voir la quantite en stock » : dans la liste. */
    public function test_la_liste_affiche_la_quantite_en_stock(): void
    {
        Product::factory()->create(['title' => 'Suivi', 'stock_quantity' => 4]);

        Livewire::test(ListProducts::class)
            ->assertCanRenderTableColumn('stock_quantity')
            ->assertTableColumnStateSet('stock_quantity', 4, Product::query()->first());
    }
}
