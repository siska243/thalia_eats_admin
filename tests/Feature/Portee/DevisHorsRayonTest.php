<?php

namespace Tests\Feature\Portee;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use App\Services\QuotationService;
use App\Settings\FoodSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DevisHorsRayonTest extends TestCase
{
    use RefreshDatabase;

    private const GOMBE = [-4.3045, 15.3075];

    private const LUBUMBASHI = [-11.6600, 27.4800];

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
        ], loadMissingValues: false);

        $this->currency = Currency::factory()->create();

        $this->livraison = Town::factory()->create([
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);

        DelivreryPrice::factory()->create([
            'town_id' => $this->livraison->id,
            'currency_id' => $this->currency->id,
        ]);

        // Le devis est derrière `auth:sanctum` : il donne un prix, et un prix
        // ne se calcule pas pour un inconnu.
        // L'ability est exigee par la route : les tokens sont scopes depuis le
        // chantier agent/MCP, et un token sans portee ne calcule pas de prix.
        Sanctum::actingAs(User::factory()->create(), ['devis:calculer']);
    }

    private function produitDe(Restaurant $restaurant): Product
    {
        return Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $this->currency->id,
            'price' => 1000.0,
        ]);
    }

    private function appeler(Product $produit, array $position = []): TestResponse
    {
        return $this->postJson('/api/quote', array_merge([
            'town' => $this->livraison->slug,
            'products' => [['uid' => Cipher::Encrypt($produit->id), 'quantity' => 1]],
        ], $position));
    }

    public function test_un_restaurant_trop_loin_est_refuse(): void
    {
        $restaurant = Restaurant::factory()
            ->located(...self::LUBUMBASHI)
            ->create(['name' => 'Al-Dar']);

        $reponse = $this->appeler($this->produitDe($restaurant));

        $reponse->assertOk();
        $reponse->assertJsonPath('disponible', false);
        $reponse->assertJsonPath('raison', QuotationService::RAISON_HORS_RAYON);
        $this->assertStringContainsString('Al-Dar', $reponse->json('message'));
    }

    public function test_un_restaurant_dans_le_rayon_est_chiffre(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $reponse = $this->appeler($this->produitDe($restaurant));

        $reponse->assertOk();
        $reponse->assertJsonPath('disponible', true);
        $reponse->assertJsonPath('raison', null);
    }

    /**
     * La position envoyee affine le centroide, quand elle est credible.
     *
     * Ici l'adresse exacte est a une quarantaine de kilometres du centre de la
     * commune de livraison : plausible dans une agglomeration etendue, et
     * au-dela du rayon de 30 km. Le centroide seul aurait dit « sur place ».
     */
    public function test_la_position_envoyee_prime_sur_le_centroide(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        // 0,36 degre de latitude, soit environ 40 km au sud de la Gombe.
        $reponse = $this->appeler($this->produitDe($restaurant), [
            'lat' => self::GOMBE[0] - 0.36, 'lng' => self::GOMBE[1],
        ]);

        $reponse->assertJsonPath('raison', QuotationService::RAISON_HORS_RAYON);
    }

    /**
     * Une position qui contredit la commune declaree est ecartee.
     *
     * Sans ce garde-fou, la regle se contourne avec deux nombres : declarer
     * une livraison a Lubumbashi, annoncer les coordonnees de la Gombe, et
     * commander dans un restaurant de la Gombe. La commande serait partie,
     * enregistree sur Lubumbashi et facturee a ses tarifs.
     *
     * Dans l'autre sens, c'est aussi ce qui permet a un client en voyage de
     * faire livrer chez lui : sa position du moment ne doit pas refuser une
     * livraison parfaitement valable.
     */
    public function test_une_position_contredisant_la_commune_est_ignoree(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        // Le client annonce etre a Lubumbashi, mais fait livrer a la Gombe,
        // ou se trouve le restaurant.
        $reponse = $this->appeler($this->produitDe($restaurant), [
            'lat' => self::LUBUMBASHI[0], 'lng' => self::LUBUMBASHI[1],
        ]);

        $reponse->assertJsonPath('disponible', true);
    }

    public function test_un_restaurant_sans_position_exploitable_reste_chiffre(): void
    {
        $restaurant = Restaurant::factory()->create([
            'town_id' => null, 'location' => null,
        ]);

        $reponse = $this->appeler($this->produitDe($restaurant));

        $reponse->assertJsonPath('disponible', true);
    }

    /** Review Focus 3 : les refus de panier passent avant celui de distance. */
    public function test_un_panier_multi_restaurant_est_refuse_pour_cette_raison_et_non_pour_la_distance(): void
    {
        $loin = Restaurant::factory()->located(...self::LUBUMBASHI)->create();
        $pres = Restaurant::factory()->located(...self::GOMBE)->create();

        $reponse = $this->postJson('/api/quote', [
            'town' => $this->livraison->slug,
            'products' => [
                ['uid' => Cipher::Encrypt($this->produitDe($loin)->id), 'quantity' => 1],
                ['uid' => Cipher::Encrypt($this->produitDe($pres)->id), 'quantity' => 1],
            ],
        ]);

        $reponse->assertJsonPath('raison', QuotationService::RAISON_MULTI_RESTAURANT);
    }
}
