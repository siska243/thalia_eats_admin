<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Town;
use App\Wrappers\Cipher;

class BudgetSuggestionService
{
    public const RAISON_BUDGET_INSUFFISANT = 'budget_insuffisant';

    public const RAISON_AUCUN_PRODUIT_DANS_CETTE_DEVISE = 'aucun_produit_dans_cette_devise';

    public const RAISON_AUCUN_RESTAURANT_DANS_CETTE_ZONE = 'aucun_restaurant_dans_cette_zone';

    private const CANDIDATS_MAX = 50;

    public function __construct(
        private readonly ProductSearchService $search,
        private readonly QuotationService $quotations,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  q, category, sub_category, lat, lng, radius
     * @return array{suggestions: array<int, array<string, mixed>>, disponible: bool, raison: ?string, option_la_moins_chere: ?array<string, mixed>}
     */
    public function suggest(float $budget, Currency $currency, Town $town, array $filters = []): array
    {
        $base = array_merge($filters, [
            'currency_id' => $currency->id,
            'town_id' => $town->id,
            'per_page' => self::CANDIDATS_MAX,
        ]);

        // Le plafond de recherche vaut exactement le budget : pour toute tranche
        // [i, m] de frais f + s, le plafond sur le plat est min(m, B - f - s) ≤ B,
        // et le cas hors tranche (frais nuls) donne B. Voir l'en-tête de la tâche.
        ['paginator' => $paginator, 'distances' => $distances] = $this->search->search(
            array_merge($base, ['price_max' => $budget, 'sort' => 'prix'])
        );

        $suggestions = [];

        foreach ($paginator->items() as $product) {
            $quotation = $this->quotations->quote(
                [['product' => $product, 'quantity' => 1]],
                $town
            );

            if (! $quotation->disponible || $quotation->total > $budget) {
                continue;
            }

            $suggestions[] = $this->presenter($product, $quotation, $distances, $budget);
        }

        if ($suggestions !== []) {
            // Le plus proche du budget d'abord : c'est ce qui en tire le plus de valeur.
            usort($suggestions, fn ($a, $b) => $b['total'] <=> $a['total']);

            return [
                'suggestions' => $suggestions,
                'disponible' => true,
                'raison' => null,
                'option_la_moins_chere' => null,
            ];
        }

        return $this->expliquerEchec($budget, $currency, $town, $base, $distances);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<int, float|null>  $distances
     * @return array{suggestions: array<int, array<string, mixed>>, disponible: bool, raison: ?string, option_la_moins_chere: ?array<string, mixed>}
     */
    private function expliquerEchec(float $budget, Currency $currency, Town $town, array $base, array $distances): array
    {
        // Sans plafond de prix cette fois : on cherche ce qui existe, pour dire
        // combien il manque plutôt que « je n'ai rien trouvé ».
        ['paginator' => $paginator] = $this->search->search(
            array_merge($base, ['sort' => 'prix', 'per_page' => 1])
        );

        $moins_cher = $paginator->items()[0] ?? null;

        if ($moins_cher === null) {
            $eligibles = $this->search->eligibleRestaurants($base);

            return [
                'suggestions' => [],
                'disponible' => false,
                'raison' => $eligibles['ids'] === []
                    ? self::RAISON_AUCUN_RESTAURANT_DANS_CETTE_ZONE
                    : self::RAISON_AUCUN_PRODUIT_DANS_CETTE_DEVISE,
                'option_la_moins_chere' => null,
            ];
        }

        $quotation = $this->quotations->quote(
            [['product' => $moins_cher, 'quantity' => 1]],
            $town
        );

        $option = $this->presenter($moins_cher, $quotation, $distances, $budget);
        $option['manque'] = round($quotation->total - $budget, 2);
        unset($option['reste']);

        return [
            'suggestions' => [],
            'disponible' => false,
            'raison' => self::RAISON_BUDGET_INSUFFISANT,
            'option_la_moins_chere' => $option,
        ];
    }

    /**
     * @param  array<int, float|null>  $distances
     * @return array<string, mixed>
     */
    private function presenter(Product $product, Quotation $quotation, array $distances, float $budget): array
    {
        return [
            'restaurant' => [
                'name' => $product->restaurant?->name,
                'slug' => $product->restaurant?->slug,
            ],
            'produit' => [
                'uid' => Cipher::Encrypt($product->id),
                'title' => $product->title,
                'slug' => $product->slug,
                'price' => (float) $product->price,
            ],
            'distance_km' => $distances[(int) $product->restaurant_id] ?? null,
            'sous_total' => $quotation->sous_total,
            'frais_livraison' => $quotation->frais_livraison,
            'service_price' => $quotation->service_price,
            'total' => $quotation->total,
            'reste' => round($budget - $quotation->total, 2),
        ];
    }
}
