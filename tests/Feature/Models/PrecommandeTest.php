<?php

namespace Tests\Feature\Models;

use App\Models\Precommande;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrecommandeTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_precommande_fraiche_est_valide(): void
    {
        $precommande = Precommande::factory()->create();

        $this->assertTrue($precommande->estValide());
        $this->assertFalse($precommande->estExpiree());
    }

    public function test_une_precommande_de_plus_de_douze_heures_est_expiree(): void
    {
        $precommande = Precommande::factory()->expiree()->create();

        $this->assertFalse($precommande->estValide());
        $this->assertTrue($precommande->estExpiree());
    }

    public function test_une_precommande_payee_n_est_plus_valide(): void
    {
        // Elle n'est pas expirée, mais elle ne peut plus servir a payer.
        $precommande = Precommande::factory()->payee()->create();

        $this->assertFalse($precommande->estValide());
        $this->assertFalse($precommande->estExpiree());
    }

    public function test_le_scope_valides_ecarte_expirees_et_payees(): void
    {
        Precommande::factory()->create();
        Precommande::factory()->expiree()->create();
        Precommande::factory()->payee()->create();

        $this->assertSame(1, Precommande::query()->valides()->count());
    }

    public function test_le_prix_de_la_ligne_est_fige_meme_si_le_produit_change(): void
    {
        $precommande = Precommande::factory()->create();
        $produit = Product::factory()->create(['price' => 1000]);

        $precommande->products()->create([
            'product_id' => $produit->id,
            'quantity' => 2,
            'price' => 1000,
        ]);

        $produit->update(['price' => 9999]);

        $ligne = $precommande->products()->first();

        $this->assertSame(1000.0, (float) $ligne->price);
        $this->assertSame(9999.0, (float) $ligne->product->price);
    }

    public function test_la_reference_ne_peut_pas_etre_confondue_avec_une_commande(): void
    {
        // commandes.refernce est un entier nu ; le webhook cherche dessus.
        $precommande = Precommande::factory()->create();

        $this->assertStringStartsWith('P-', $precommande->refernce);
        $this->assertFalse(ctype_digit($precommande->refernce));
    }
}
