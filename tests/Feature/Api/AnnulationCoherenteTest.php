<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Currency;
use App\Models\DelivreryDriver;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * L'annulation s'ecrit a deux endroits — status_id et cancel_at — et le
 * formulaire d'administration les expose comme deux champs independants.
 *
 * Six lectures supposaient que le statut suffisait. Aucune n'avait tort
 * isolement : c'est le modele qui l'etait. Corriger les six lectures aurait
 * laisse la septieme a ecrire, alors la coherence est desormais garantie a
 * l'ecriture.
 */
class AnnulationCoherenteTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([[2, 'En cours', 'en-cours'], [3, 'Livrer', 'livrer'], [4, 'Annuler', 'annuler']] as [$id, $titre, $slug]) {
            Status::query()->firstOrCreate(['id' => $id], ['title' => $titre, 'slug' => $slug]);
        }
    }

    private function commande(array $attributs = [], ?User $client = null): Commande
    {
        $restaurant = $attributs['restaurant'] ?? Restaurant::factory()->create();
        unset($attributs['restaurant']);

        $commande = Commande::query()->create(array_merge([
            'user_id' => ($client ?? User::factory()->create())->id,
            'status_id' => 2,
            'town_id' => Town::factory()->create()->id,
            'refernce' => 'CMD-'.uniqid(),
            'global_price' => 10,
            'accepted_at' => now(),
        ], $attributs));

        CommandeProduct::query()->create([
            'commande_id' => $commande->id,
            'user_id' => $commande->user_id,
            'product_id' => Product::factory()->create(['restaurant_id' => $restaurant->id])->id,
            'currency_id' => Currency::query()->firstOrCreate(
                ['code' => 'USD'],
                ['title' => 'Dollar', 'slug' => 'usd']
            )->id,
            'quantity' => 1,
            'price' => 10,
        ]);

        return $commande;
    }

    public function test_poser_la_date_d_annulation_bascule_le_statut(): void
    {
        $commande = $this->commande();

        // Le geste de l'administrateur : renseigner cancel_at, ne pas toucher
        // au statut.
        $commande->cancel_at = now();
        $commande->save();

        $this->assertSame(Commande::STATUT_ANNULEE, (int) $commande->fresh()->status_id);
    }

    public function test_basculer_le_statut_pose_la_date_d_annulation(): void
    {
        $commande = $this->commande();

        $commande->status_id = Commande::STATUT_ANNULEE;
        $commande->save();

        $this->assertNotNull($commande->fresh()->cancel_at);
    }

    public function test_une_commande_vivante_n_est_pas_touchee(): void
    {
        $commande = $this->commande();

        $commande->global_price = 42;
        $commande->save();

        $this->assertSame(2, (int) $commande->fresh()->status_id);
        $this->assertNull($commande->fresh()->cancel_at);
    }

    public function test_le_restaurateur_ne_voit_plus_de_commande_annulee_a_preparer(): void
    {
        $patron = User::factory()->create();
        $restaurant = Restaurant::factory()->create(['user_id' => $patron->id]);

        $aPreparer = $this->commande(['restaurant' => $restaurant]);
        $annulee = $this->commande(['restaurant' => $restaurant]);

        // Ligne heritee : ecrite avant la garantie, statut jamais bascule.
        DB::table('commandes')->where('id', $annulee->id)->update(['cancel_at' => now()]);

        Sanctum::actingAs($patron, ['*']);

        $reponse = $this->getJson('/api/user/restaurant-current-order')->assertStatus(200);

        $this->assertCount(1, $reponse->json());
        $this->assertSame($aPreparer->refernce, $reponse->json('0.reference'));
    }

    public function test_le_client_ne_voit_plus_sa_commande_annulee_comme_en_route(): void
    {
        $client = User::factory()->create();

        $enRoute = $this->commande([], $client);
        $annulee = $this->commande([], $client);

        DB::table('commandes')->where('id', $annulee->id)->update(['cancel_at' => now()]);

        Sanctum::actingAs($client, ['*']);

        $reponse = $this->getJson('/api/user/commande/tracking')->assertStatus(200);

        $this->assertCount(1, $reponse->json());
        $this->assertSame($enRoute->refernce, $reponse->json('0.reference'));
    }

    public function test_le_tableau_de_bord_livreur_ne_repond_plus_500(): void
    {
        $livreurUser = User::factory()->create();
        $livreur = DelivreryDriver::query()->create([
            'user_id' => $livreurUser->id,
            'id_card' => 'CARTE-'.$livreurUser->id,
            'is_active' => true,
        ]);

        // Les courses d'un autre livreur ne doivent pas entrer dans ses compteurs.
        $autreUser = User::factory()->create();
        $autre = DelivreryDriver::query()->create([
            'user_id' => $autreUser->id,
            'id_card' => 'CARTE-'.$autreUser->id,
            'is_active' => true,
        ]);

        $this->commande(['delivrery_driver_id' => $livreur->id, 'status_id' => 3, 'delivery_at' => now()]);
        $this->commande(['delivrery_driver_id' => $autre->id, 'status_id' => 3, 'delivery_at' => now()]);

        Sanctum::actingAs($livreurUser, ['*']);

        // getCurrentRestaurant() n'existe pas dans ce controleur : l'appel
        // levait une Error, non rattrapee par catch (Exception).
        $reponse = $this->getJson('/api/user/delivery-dash')->assertStatus(200);

        $this->assertSame(1, $reponse->json('order.order_delivery'));
    }
}
