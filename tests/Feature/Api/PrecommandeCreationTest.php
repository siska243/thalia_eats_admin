<?php

namespace Tests\Feature\Api;

use App\Enums\TokenAbility;
use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Precommande;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrecommandeCreationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Town, 1: Currency, 2: Product}
     */
    private function contexte(float $prix = 1500): array
    {
        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        $restaurant = Restaurant::factory()->create(['town_id' => $town->id]);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 2000, 'service_price' => 500,
        ]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $currency->id,
            'price' => $prix,
        ]);

        return [$town, $currency, $product];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Town $town, Product $product, int $quantite = 2): array
    {
        return [
            'town' => $town->slug,
            'products' => [['uid' => Cipher::Encrypt($product->id), 'quantity' => $quantite]],
        ];
    }

    public function test_l_endpoint_exige_l_ability_de_creation(): void
    {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::CatalogueLire->value]);
        [$town, , $product] = $this->contexte();

        $this->postJson('/api/precommandes', $this->payload($town, $product))->assertStatus(403);
    }

    public function test_il_cree_une_precommande_chiffree_par_le_serveur(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $response = $this->postJson('/api/precommandes', $this->payload($town, $product));

        $response->assertStatus(201)->assertJson([
            'data' => [
                'sous_total' => 3000,
                'frais_livraison' => 2000,
                'service_price' => 500,
                'total' => 5500,
            ],
        ]);

        $this->assertNotEmpty($response->json('data.lien_paiement'));
        $this->assertNotEmpty($response->json('data.expires_at'));
    }

    public function test_un_prix_envoye_par_l_appelant_est_ignore(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $payload = $this->payload($town, $product);
        $payload['total'] = 1;
        $payload['total_price'] = 1;
        $payload['pricing'] = ['frais_livraison' => 0, 'service_price' => 0];

        $this->postJson('/api/precommandes', $payload)->assertStatus(201)
            ->assertJson(['data' => ['total' => 5500]]);

        $this->assertSame(5500.0, (float) Precommande::query()->first()->total);
    }

    public function test_une_precommande_se_cree_avec_la_seule_commune(): void
    {
        // L'assistant ne collecte plus ni adresse ni destinataire : une adresse
        // dictee a une machine est une adresse mal recopiee. Le client les
        // saisira lui-meme sur la page du lien de paiement.
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $this->postJson('/api/precommandes', $this->payload($town, $product))->assertStatus(201);

        $precommande = Precommande::query()->first();

        $this->assertNull($precommande->adresse_delivery);
        $this->assertNull($precommande->recipient_name);
        $this->assertNull($precommande->recipient_phone);
        $this->assertSame($town->id, $precommande->town_id);
        $this->assertFalse($precommande->coordonneesCompletes());
    }

    public function test_une_adresse_envoyee_par_l_assistant_est_ignoree(): void
    {
        // La signature ne les accepte plus : rien ne doit se glisser en base
        // par une cle de requete restee dans un ancien client.
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $payload = $this->payload($town, $product);
        $payload['adresse'] = ['adresse' => 'Avenue dictee de travers'];
        $payload['destinataire'] = ['name' => 'Inconnu', 'phone' => '+243810000000'];
        $payload['adresse_delivery'] = 'Avenue dictee de travers';
        $payload['recipient_name'] = 'Inconnu';

        $this->postJson('/api/precommandes', $payload)->assertStatus(201);

        $precommande = Precommande::query()->first();

        $this->assertNull($precommande->adresse_delivery);
        $this->assertNull($precommande->recipient_name);
    }

    public function test_une_town_sans_tarif_actif_est_refusee(): void
    {
        // Le moteur tolère le zéro pour rester fidèle au web ; une pré-commande
        // créée par une machine ne doit pas promettre une livraison gratuite.
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        $product = Product::factory()->create(['currency_id' => $currency->id]);

        $this->postJson('/api/precommandes', $this->payload($town, $product))
            ->assertStatus(400)
            ->assertJson(['error' => 'aucun_tarif_livraison']);
    }

    public function test_un_panier_multi_restaurants_est_refuse(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, $currency, $product] = $this->contexte();

        $autre = Product::factory()->create(['currency_id' => $currency->id]);

        $payload = $this->payload($town, $product);
        $payload['products'][] = ['uid' => Cipher::Encrypt($autre->id), 'quantity' => 1];

        $this->postJson('/api/precommandes', $payload)
            ->assertStatus(400)
            ->assertJson(['error' => 'multi_restaurant']);
    }

    public function test_le_prix_reste_fige_quand_le_produit_change(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $this->postJson('/api/precommandes', $this->payload($town, $product))->assertStatus(201);

        $product->update(['price' => 9999]);

        $precommande = Precommande::query()->with('products')->first();

        $this->assertSame(5500.0, (float) $precommande->total);
        $this->assertSame(1500.0, (float) $precommande->products->first()->price);
    }

    public function test_la_precommande_expire_dans_douze_heures(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $this->postJson('/api/precommandes', $this->payload($town, $product))->assertStatus(201);

        $precommande = Precommande::query()->first();

        $this->assertTrue($precommande->expires_at->between(now()->addHours(11), now()->addHours(13)));
    }

    public function test_le_panier_est_borne(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $payload = $this->payload($town, $product);
        $payload['products'] = array_fill(0, 101, ['uid' => Cipher::Encrypt($product->id), 'quantity' => 1]);

        $this->postJson('/api/precommandes', $payload)->assertStatus(422);
    }

    public function test_une_quantite_fractionnaire_est_refusee(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $payload = $this->payload($town, $product);
        $payload['products'][0]['quantity'] = 2.5;

        $this->postJson('/api/precommandes', $payload)->assertStatus(422);
    }

    public function test_une_quantite_au_dela_de_cinquante_est_refusee(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $payload = $this->payload($town, $product);
        $payload['products'][0]['quantity'] = 51;

        $this->postJson('/api/precommandes', $payload)->assertStatus(422);
    }

    public function test_un_panier_hors_tranche_est_distingue_d_une_town_non_desservie(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        $restaurant = Restaurant::factory()->create(['town_id' => $town->id]);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 5000,
            'frais' => 2000, 'service_price' => 500,
        ]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $currency->id,
            'price' => 50000,
        ]);

        $this->postJson('/api/precommandes', $this->payload($town, $product, 1))
            ->assertStatus(400)
            ->assertJson(['error' => 'panier_hors_tranche']);
    }
}
