<?php

namespace Tests\Feature;

use App\Http\Resources\CommandeResource;
use App\Models\Commande;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Une commande expose sa devise, meme sans produit.
 *
 * La devise n'avait pas de colonne et n'etait exposee nulle part : web et
 * mobile la deduisaient tous deux de `products[0].currency`. Les commandes
 * dont les produits ont disparu — il en existe en base — affichaient donc
 * leur total sans unite : « 2,3 », qu'on ne peut ni lire ni verifier.
 */
class DeviseCommandeTest extends TestCase
{
    use RefreshDatabase;

    public function test_elle_retombe_sur_la_devise_active_sans_produit(): void
    {
        $devise = Currency::query()->create([
            'title' => 'Dollars',
            'code' => 'USD',
            'is_active' => 1,
        ]);

        // Pas de fabrique pour Commande dans ce depot, et la ressource ne lit
        // que des attributs : une instance non persistee suffit, avec la
        // relation produits explicitement vide — c'est le cas qu'on teste.
        $commande = new Commande(['global_price' => 2.3]);
        $commande->id = 1;
        $commande->setRelation('product', collect());

        $rendu = (new CommandeResource($commande))->toArray(Request::create('/'));

        $this->assertSame($devise->code, $rendu['currency']?->code);
    }
}
