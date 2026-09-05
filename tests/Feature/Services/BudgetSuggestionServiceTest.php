<?php

namespace Tests\Feature\Services;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Services\BudgetSuggestionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

class BudgetSuggestionServiceTest extends TestCase
{
    // DatabaseTruncation, PAS RefreshDatabase : un index FULLTEXT InnoDB n'est
    // pas visible depuis MATCH() ... AGAINST() à l'intérieur de la transaction
    // non validée dans laquelle RefreshDatabase enferme chaque test. Le test
    // du filtre texte verrait alors zéro ligne. Constaté en tâche 4.
    use DatabaseTruncation;

    /**
     * DatabaseTruncation tronque en setUp(), pas en tearDown() : sans ceci, les
     * lignes committées par le dernier test de cette classe survivraient et
     * seraient visibles par une classe RefreshDatabase exécutée ensuite.
     */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    private BudgetSuggestionService $service;

    private Town $town;

    private Currency $currency;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BudgetSuggestionService::class);
        $this->town = Town::factory()->create();
        $this->currency = Currency::factory()->create();
        $this->restaurant = Restaurant::factory()->create(['town_id' => $this->town->id]);

        // Tranche unique : 2000 de livraison + 500 de service pour tout panier
        // de 0 à 100 000.
        DelivreryPrice::factory()->create([
            'town_id' => $this->town->id,
            'currency_id' => $this->currency->id,
            'interval_pricing' => 0,
            'interval_max_price' => 100000,
            'frais' => 2000,
            'service_price' => 500,
        ]);
    }

    private function plat(string $title, float $price): Product
    {
        return Product::factory()->create([
            'title' => $title,
            'price' => $price,
            'restaurant_id' => $this->restaurant->id,
            'currency_id' => $this->currency->id,
        ]);
    }

    public function test_il_retient_un_plat_dont_le_total_tient_dans_le_budget(): void
    {
        $this->plat('Beignets', 2000);   // 2000 + 2500 = 4500

        $result = $this->service->suggest(5000.0, $this->currency, $this->town);

        $this->assertTrue($result['disponible']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame(4500.0, $result['suggestions'][0]['total']);
        $this->assertSame(500.0, $result['suggestions'][0]['reste']);
    }

    public function test_il_ecarte_un_plat_dont_les_frais_font_depasser_le_budget(): void
    {
        // Le plat seul tient dans le budget, mais pas une fois livre.
        $this->plat('Brochette', 4000);   // 4000 + 2500 = 6500 > 5000

        $result = $this->service->suggest(5000.0, $this->currency, $this->town);

        $this->assertFalse($result['disponible']);
        $this->assertSame('budget_insuffisant', $result['raison']);
        $this->assertSame([], $result['suggestions']);
    }

    public function test_quand_rien_ne_rentre_il_donne_l_option_la_moins_chere_et_le_manque(): void
    {
        $this->plat('Brochette', 4000);   // total 6500
        $this->plat('Poulet entier', 20000);

        $result = $this->service->suggest(5000.0, $this->currency, $this->town);

        $this->assertFalse($result['disponible']);
        $this->assertNotNull($result['option_la_moins_chere']);
        $this->assertSame('Brochette', $result['option_la_moins_chere']['produit']['title']);
        $this->assertSame(6500.0, $result['option_la_moins_chere']['total']);
        $this->assertSame(1500.0, $result['option_la_moins_chere']['manque']);
    }

    public function test_il_signale_l_absence_de_produit_dans_la_devise_demandee(): void
    {
        $usd = Currency::factory()->usd()->create();
        $this->plat('Beignets', 2000);

        $result = $this->service->suggest(5000.0, $usd, $this->town);

        $this->assertFalse($result['disponible']);
        $this->assertSame('aucun_produit_dans_cette_devise', $result['raison']);
        $this->assertNull($result['option_la_moins_chere']);
    }

    public function test_sans_aucune_tranche_active_la_livraison_est_gratuite(): void
    {
        // Comportement conservé de la production : hors tranche, frais nuls.
        DelivreryPrice::query()->delete();
        $this->plat('Brochette', 4000);

        $result = $this->service->suggest(5000.0, $this->currency, $this->town);

        $this->assertTrue($result['disponible']);
        $this->assertSame(4000.0, $result['suggestions'][0]['total']);
        $this->assertSame(0.0, $result['suggestions'][0]['frais_livraison']);
    }

    public function test_les_suggestions_les_plus_proches_du_budget_arrivent_en_premier(): void
    {
        $this->plat('Beignets', 500);      // total 3000
        $this->plat('Riz', 2000);          // total 4500
        $this->plat('Sombe', 1200);        // total 3700

        $result = $this->service->suggest(5000.0, $this->currency, $this->town);

        $totaux = array_column($result['suggestions'], 'total');
        $this->assertSame([4500.0, 3700.0, 3000.0], $totaux);
    }

    public function test_le_filtre_texte_restreint_les_suggestions(): void
    {
        $this->plat('Poulet moambe', 2000);
        $this->plat('Salade verte', 1000);

        $result = $this->service->suggest(5000.0, $this->currency, $this->town, ['q' => 'poulet']);

        $this->assertCount(1, $result['suggestions']);
        $this->assertSame('Poulet moambe', $result['suggestions'][0]['produit']['title']);
    }
}
