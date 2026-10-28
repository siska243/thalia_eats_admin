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

/**
 * « Je suis au Maroc mais je vois un resto de Kinshasa. »
 *
 * La regle mesurait la distance entre le restaurant et l'ADRESSE DE
 * LIVRAISON, jamais la position physique du client. Les deux lectures sont
 * legitimes — commander pour sa famille a distance, ou chercher a manger la
 * ou l'on se trouve — et elles s'excluent. C'est donc une option
 * d'administration, eteinte par defaut.
 */
class PositionClientTest extends TestCase
{
    use RefreshDatabase;

    private const GOMBE = [-4.3045, 15.3075];

    private const LIMETE = [-4.3700, 15.3500];

    /** Casablanca : plus de 5 000 km de Kinshasa. */
    private const MAROC = [33.5731, -7.5898];

    private Town $livraison;

    private Currency $currency;

    private function reglages(bool $positionClient): void
    {
        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
            'use_client_position' => $positionClient,
            'city_first' => false,
            'stock_actif' => true,
            'montant_minimum_commande' => 0.0,
        ], loadMissingValues: false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reglages(false);

        $this->currency = Currency::factory()->create();

        $this->livraison = Town::factory()->create([
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);

        DelivreryPrice::factory()->create([
            'town_id' => $this->livraison->id,
            'currency_id' => $this->currency->id,
        ]);
    }

    private function produitDeGombe(): Product
    {
        $restaurant = Restaurant::factory()
            ->located(...self::GOMBE)
            ->create(['name' => 'Al-Dar']);

        return Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $this->currency->id,
            'price' => 1000.0,
        ]);
    }

    private function catalogue(?array $position): TestResponse
    {
        $url = '/api/list-restaurant?town='.$this->livraison->slug;

        if ($position) {
            $url .= '&client_lat='.$position[0].'&client_lng='.$position[1];
        }

        return $this->getJson($url);
    }

    private function devis(Product $produit, ?array $position): TestResponse
    {
        Sanctum::actingAs(User::factory()->create(), ['devis:calculer']);

        return $this->postJson('/api/quote', array_merge([
            'town' => $this->livraison->slug,
            'products' => [['uid' => Cipher::Encrypt((string) $produit->id), 'quantity' => 1]],
        ], $position ? ['client_lat' => $position[0], 'client_lng' => $position[1]] : []));
    }

    // --- L'option expose aux applications -----------------------------------

    public function test_les_donnees_par_defaut_annoncent_l_option(): void
    {
        $this->reglages(true);

        $this->getJson('/api/default')
            ->assertOk()
            ->assertJsonPath('reglages.position_client', true);
    }

    public function test_l_option_est_eteinte_par_defaut_dans_les_donnees(): void
    {
        $this->getJson('/api/default')->assertJsonPath('reglages.position_client', false);
    }

    // --- Option eteinte : rien ne change ------------------------------------

    public function test_option_eteinte_un_client_au_maroc_commande_quand_meme(): void
    {
        $this->produitDeGombe();

        $this->catalogue(self::MAROC)->assertJsonPath('data.0.livrable', true);
    }

    // --- Option allumee -----------------------------------------------------

    public function test_option_allumee_le_catalogue_grise_depuis_le_maroc(): void
    {
        $this->reglages(true);
        $this->produitDeGombe();

        $reponse = $this->catalogue(self::MAROC);

        $reponse->assertJsonPath('data.0.livrable', false);
        $this->assertStringContainsString('Al-Dar', $reponse->json('data.0.portee_message'));
    }

    public function test_option_allumee_un_client_sur_place_commande(): void
    {
        $this->reglages(true);
        $this->produitDeGombe();

        $this->catalogue(self::LIMETE)->assertJsonPath('data.0.livrable', true);
    }

    public function test_option_allumee_le_devis_refuse_depuis_le_maroc(): void
    {
        $this->reglages(true);

        $this->devis($this->produitDeGombe(), self::MAROC)
            ->assertJsonPath('raison', QuotationService::RAISON_HORS_RAYON);
    }

    public function test_option_allumee_le_devis_passe_sur_place(): void
    {
        $this->reglages(true);

        $this->devis($this->produitDeGombe(), self::LIMETE)
            ->assertJsonPath('disponible', true);
    }

    /**
     * Permission refusee : l'application n'envoie aucune position, et rien
     * n'est refuse de ce fait. Un catalogue vide pour une permission declinee
     * serait pire que la regle qu'on cherche a tenir.
     */
    public function test_option_allumee_sans_position_rien_ne_se_bloque(): void
    {
        $this->reglages(true);
        $this->produitDeGombe();

        $this->catalogue(null)->assertJsonPath('data.0.livrable', true);
    }

    /**
     * Le filet de securite des applications deja installees : elles n'envoient
     * jamais `client_lat`, et doivent se comporter exactement comme avant.
     */
    public function test_une_application_qui_ignore_l_option_fonctionne_comme_avant(): void
    {
        $this->reglages(true);
        $produit = $this->produitDeGombe();

        $this->devis($produit, null)->assertJsonPath('disponible', true);
    }
}
