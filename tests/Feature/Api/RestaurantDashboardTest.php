<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\Currency;
use App\Models\CommandeProduct;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Les quatre compteurs du tableau de bord interrogeaient Commande sans
 * aucune contrainte de restaurant : chaque restaurateur voyait le total de
 * la plateforme, celui de ses concurrents compris.
 */
class RestaurantDashboardTest extends TestCase
{
    use DatabaseTruncation;

    private function statut(int $id, string $titre): Status
    {
        return Status::query()->firstOrCreate(
            ['id' => $id],
            ['title' => $titre, 'slug' => str($titre)->slug()->value()]
        );
    }

    /** Une commande rattachee a un restaurant via un de ses produits. */
    private function commandePour(Restaurant $restaurant, int $statusId): Commande
    {
        $produit = Product::factory()->create(['restaurant_id' => $restaurant->id]);

        $commande = Commande::query()->create([
            'user_id' => $restaurant->user_id,
            'status_id' => $statusId,
            'refernce' => (string) random_int(100000, 999999),
            'global_price' => 10,
            'accepted_at' => now(),
        ]);

        CommandeProduct::query()->create([
            'commande_id' => $commande->id,
            'product_id' => $produit->id,
            'user_id' => $restaurant->user_id,
            'currency_id' => Currency::factory()->create()->id,
            'quantity' => 1,
            'price' => 10,
        ]);

        return $commande;
    }

    public function test_les_compteurs_ignorent_les_commandes_des_autres_restaurants(): void
    {
        $this->statut(2, 'En cours');
        $this->statut(3, 'Livrer');
        $this->statut(4, 'Annuler');

        $patron = User::factory()->create();
        $mien = Restaurant::factory()->create(['user_id' => $patron->id]);

        $concurrent = Restaurant::factory()->create(['user_id' => User::factory()->create()->id]);

        // Une seule commande chez moi, trois chez le concurrent.
        $this->commandePour($mien, 2);

        $this->commandePour($concurrent, 2);
        $this->commandePour($concurrent, 3);
        $this->commandePour($concurrent, 4);

        Sanctum::actingAs($patron, ['*']);

        $response = $this->getJson('/api/user/restaurant-dash');
        $response->assertStatus(200);

        $order = $response->json('order');

        $this->assertSame(1, $order['current'], 'une seule commande en cours chez ce restaurant');
        $this->assertSame(0, $order['order_delivery'], 'aucune livraison chez ce restaurant');
        $this->assertSame(0, $order['order_cancel'], 'aucune annulation chez ce restaurant');
    }

    public function test_un_restaurant_sans_commande_affiche_des_compteurs_a_zero(): void
    {
        $this->statut(2, 'En cours');

        $patron = User::factory()->create();
        Restaurant::factory()->create(['user_id' => $patron->id]);

        $concurrent = Restaurant::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->commandePour($concurrent, 2);

        Sanctum::actingAs($patron, ['*']);

        $order = $this->getJson('/api/user/restaurant-dash')->json('order');

        $this->assertSame(0, $order['current']);
    }
}
