<?php

namespace Tests\Feature\Services;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuotationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(QuotationService::class);
    }

    /**
     * @param  array<int, array{0: Product, 1: int}>  $pairs
     * @return array<int, array{product: Product, quantity: int}>
     */
    private function lines(array $pairs): array
    {
        return array_map(fn ($pair) => ['product' => $pair[0], 'quantity' => $pair[1]], $pairs);
    }

    public function test_un_panier_vide_est_refuse(): void
    {
        $town = Town::factory()->create();

        $quotation = $this->service->quote([], $town);

        $this->assertFalse($quotation->disponible);
        $this->assertSame(QuotationService::RAISON_PANIER_VIDE, $quotation->raison);
    }

    public function test_une_quantite_nulle_ou_negative_est_refusee(): void
    {
        $town = Town::factory()->create();
        $product = Product::factory()->create();

        $quotation = $this->service->quote($this->lines([[$product, 0]]), $town);

        $this->assertFalse($quotation->disponible);
        $this->assertSame(QuotationService::RAISON_QUANTITE_INVALIDE, $quotation->raison);
    }

    public function test_des_produits_de_restaurants_differents_sont_refuses(): void
    {
        $town = Town::factory()->create();
        $currency = Currency::factory()->create();

        $a = Product::factory()->create(['currency_id' => $currency->id]);
        $b = Product::factory()->create(['currency_id' => $currency->id]);

        $quotation = $this->service->quote($this->lines([[$a, 1], [$b, 1]]), $town);

        $this->assertFalse($quotation->disponible);
        $this->assertSame(QuotationService::RAISON_MULTI_RESTAURANT, $quotation->raison);
    }

    public function test_des_produits_de_devises_differentes_sont_refuses(): void
    {
        $town = Town::factory()->create();
        $restaurant = Restaurant::factory()->create();

        $cdf = Currency::factory()->create();
        $usd = Currency::factory()->usd()->create();

        $a = Product::factory()->create(['restaurant_id' => $restaurant->id, 'currency_id' => $cdf->id]);
        $b = Product::factory()->create(['restaurant_id' => $restaurant->id, 'currency_id' => $usd->id]);

        $quotation = $this->service->quote($this->lines([[$a, 1], [$b, 1]]), $town);

        $this->assertFalse($quotation->disponible);
        $this->assertSame(QuotationService::RAISON_DEVISES_MELANGEES, $quotation->raison);
    }

    public function test_un_restaurant_attendu_qui_ne_correspond_pas_est_refuse(): void
    {
        $town = Town::factory()->create();
        $product = Product::factory()->create();

        $quotation = $this->service->quote(
            $this->lines([[$product, 1]]),
            $town,
            (int) $product->restaurant_id + 999
        );

        $this->assertFalse($quotation->disponible);
        $this->assertSame(QuotationService::RAISON_RESTAURANT_INATTENDU, $quotation->raison);
    }

    public function test_le_sous_total_multiplie_le_prix_par_la_quantite(): void
    {
        $town = Town::factory()->create();
        $restaurant = Restaurant::factory()->create();
        $currency = Currency::factory()->create();

        DelivreryPrice::factory()->create([
            'town_id' => $town->id,
            'currency_id' => $currency->id,
            'interval_pricing' => 0,
            'interval_max_price' => 100000,
            'frais' => 2000,
            'service_price' => 500,
        ]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $currency->id,
            'price' => 1500,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 3]]), $town);

        $this->assertTrue($quotation->disponible);
        $this->assertSame(4500.0, $quotation->sous_total);
        $this->assertSame(2000.0, $quotation->frais_livraison);
        $this->assertSame(500.0, $quotation->service_price);
        $this->assertSame(7000.0, $quotation->total);
    }

    public function test_le_prix_promotionnel_est_ignore(): void
    {
        $town = Town::factory()->create();
        $currency = Currency::factory()->create();

        DelivreryPrice::factory()->create([
            'town_id' => $town->id,
            'currency_id' => $currency->id,
            'frais' => 0,
            'service_price' => 0,
        ]);

        $product = Product::factory()->create([
            'currency_id' => $currency->id,
            'price' => 1000,
            'promotionnalPrice' => 400,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame(1000.0, $quotation->sous_total);
    }

    /**
     * Crée un produit à `price` dans une town, et renvoie [Town, Product, Currency].
     *
     * @return array{0: Town, 1: Product, 2: Currency}
     */
    private function contexte(float $price): array
    {
        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        $product = Product::factory()->create([
            'currency_id' => $currency->id,
            'price' => $price,
        ]);

        return [$town, $product, $currency];
    }

    public function test_la_borne_basse_de_la_tranche_est_inclusive(): void
    {
        [$town, $product, $currency] = $this->contexte(5000);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 5000, 'interval_max_price' => 9000,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame(3000.0, $quotation->frais_livraison);
        $this->assertSame(8700.0, $quotation->total);
        $this->assertSame([], $quotation->warnings);
    }

    public function test_la_borne_haute_de_la_tranche_est_inclusive(): void
    {
        [$town, $product, $currency] = $this->contexte(9000);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 5000, 'interval_max_price' => 9000,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame(3000.0, $quotation->frais_livraison);
        $this->assertSame([], $quotation->warnings);
    }

    public function test_un_sous_total_au_dessus_de_toutes_les_tranches_ne_paie_aucun_frais(): void
    {
        [$town, $product, $currency] = $this->contexte(50000);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 9000,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertTrue($quotation->disponible);
        $this->assertSame(0.0, $quotation->frais_livraison);
        $this->assertSame(0.0, $quotation->service_price);
        $this->assertSame(50000.0, $quotation->total);
        $this->assertContains(QuotationService::WARNING_HORS_TRANCHE, $quotation->warnings);
    }

    public function test_une_town_sans_aucune_tranche_active_ne_paie_aucun_frais(): void
    {
        [$town, $product, $currency] = $this->contexte(5000);

        DelivreryPrice::factory()->inactive()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame(0.0, $quotation->frais_livraison);
        $this->assertContains(QuotationService::WARNING_AUCUN_TARIF_ACTIF, $quotation->warnings);
    }

    public function test_une_tranche_a_interval_max_price_zero_ne_matche_jamais(): void
    {
        [$town, $product, $currency] = $this->contexte(5000);

        // Cas réel : interval_max_price a été ajouté le 2025-05-25 avec default(0).
        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 0,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame(0.0, $quotation->frais_livraison);
        $this->assertContains(QuotationService::WARNING_HORS_TRANCHE, $quotation->warnings);
    }

    public function test_les_tranches_d_une_autre_town_sont_ignorees(): void
    {
        [$town, $product, $currency] = $this->contexte(5000);
        $autre_town = Town::factory()->create();

        DelivreryPrice::factory()->create([
            'town_id' => $autre_town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame(0.0, $quotation->frais_livraison);
        $this->assertContains(QuotationService::WARNING_AUCUN_TARIF_ACTIF, $quotation->warnings);
    }

    public function test_la_premiere_tranche_par_id_gagne_quand_deux_se_chevauchent(): void
    {
        [$town, $product, $currency] = $this->contexte(5000);

        $premiere = DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 1000, 'service_price' => 100,
        ]);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 9000, 'service_price' => 900,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame($premiere->id, $quotation->bracket?->id);
        $this->assertSame(1000.0, $quotation->frais_livraison);
    }

    public function test_une_tranche_dans_une_autre_devise_est_signalee_mais_appliquee(): void
    {
        [$town, $product, $currency] = $this->contexte(5000);
        $usd = Currency::factory()->usd()->create();

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $usd->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        // Le client ne compare pas les devises : on reste bug-compatible.
        $this->assertTrue($quotation->disponible);
        $this->assertSame(8700.0, $quotation->total);
        $this->assertContains(QuotationService::WARNING_DEVISE_TRANCHE_DIFFERENTE, $quotation->warnings);
    }
}
