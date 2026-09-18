<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Town;
use App\Wrappers\Cipher;

class BudgetSuggestionService
{
    public const RAISON_BUDGET_INSUFFISANT = 'budget_insuffisant';

    public const RAISON_AUCUN_PRODUIT_CORRESPONDANT = 'aucun_produit_correspondant';

    public const RAISON_AUCUN_PRODUIT_DANS_CETTE_DEVISE = 'aucun_produit_dans_cette_devise';

    public const RAISON_AUCUN_PRODUIT_DISPONIBLE = 'aucun_produit_disponible';

    public const RAISON_AUCUN_RESTAURANT_DANS_CETTE_ZONE = 'aucun_restaurant_dans_cette_zone';

    private const CANDIDATS_MAX = 50;

    /**
     * Borne de l'étage 1 de la cascade d'échec : assez petit pour rester bon
     * marché sur un chemin qui échoue déjà, assez grand pour trouver le vrai
     * minimum par total (voir parTotalMinimal()).
     */
    private const CANDIDATS_ECHEC = 10;

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

            // Comparaison à la précision monétaire, pas en flottant brut : `total`
            // est une somme à trois termes délibérément non arrondie (fidélité au
            // client JS), donc un budget exactement atteint peut sinon être rejeté
            // (0.1 + 0.2 > 0.3 en flottant, alors que l'écart réel est nul).
            if (! $quotation->disponible || round($quotation->total - $budget, 2) > 0) {
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

        return $this->expliquerEchec($budget, $town, $base, $distances);
    }

    /**
     * Cascade de diagnostics, une raison par cause réelle. `eligibleRestaurants()`
     * ne filtre que sur la géographie (town_id, lat, lng, radius) : il ne peut
     * donc jamais servir seul à distinguer "pas de produit dans cette zone" de
     * "pas de produit dans cette devise" ou "filtre texte trop restrictif" — d'où
     * les étages successifs, chacun retirant un filtre de plus.
     *
     * @param  array<string, mixed>  $base  filtres complets (q, category,
     *                                      sub_category, lat, lng, radius,
     *                                      currency_id, town_id, per_page)
     * @param  array<int, float|null>  $distances
     * @return array{suggestions: array<int, array<string, mixed>>, disponible: bool, raison: ?string, option_la_moins_chere: ?array<string, mixed>}
     */
    private function expliquerEchec(float $budget, Town $town, array $base, array $distances): array
    {
        // Étage 1 : tous les filtres conservés, seul le plafond de prix saute.
        // Si des produits existent, la cause est le budget.
        ['paginator' => $paginator] = $this->search->search(
            array_merge($base, ['sort' => 'prix', 'per_page' => self::CANDIDATS_ECHEC])
        );

        $candidats = $paginator->items();

        if ($candidats !== []) {
            $meilleur = $this->parTotalMinimal($candidats, $town);

            if ($meilleur !== null) {
                [$produit, $quotation] = $meilleur;

                $option = $this->presenter($produit, $quotation, $distances, $budget);
                $option['manque'] = round($quotation->total - $budget, 2);
                unset($option['reste']);

                return [
                    'suggestions' => [],
                    'disponible' => false,
                    'raison' => self::RAISON_BUDGET_INSUFFISANT,
                    'option_la_moins_chere' => $option,
                ];
            }
        }

        // Étage 2 : sans le filtre texte/catégorie. S'il en ressort quelque
        // chose, c'est ce filtre qui excluait tout.
        $sans_texte = array_diff_key($base, array_flip(['q', 'category', 'sub_category']));

        ['paginator' => $paginator] = $this->search->search(
            array_merge($sans_texte, ['sort' => 'prix', 'per_page' => 1])
        );

        if ($paginator->items() !== []) {
            return $this->echec(self::RAISON_AUCUN_PRODUIT_CORRESPONDANT);
        }

        // Étage 3 : sans la devise non plus. S'il en ressort quelque chose,
        // la devise demandée est bien la cause.
        $sans_devise = array_diff_key($sans_texte, array_flip(['currency_id']));

        ['paginator' => $paginator] = $this->search->search(
            array_merge($sans_devise, ['sort' => 'prix', 'per_page' => 1])
        );

        if ($paginator->items() !== []) {
            return $this->echec(self::RAISON_AUCUN_PRODUIT_DANS_CETTE_DEVISE);
        }

        // Étage 4 : plus aucun filtre catalogue, seule la géographie reste.
        // Aucun restaurant éligible, ou des restaurants sans aucun produit actif.
        $eligibles = $this->search->eligibleRestaurants($sans_devise);

        return $this->echec(
            $eligibles['ids'] === []
                ? self::RAISON_AUCUN_RESTAURANT_DANS_CETTE_ZONE
                : self::RAISON_AUCUN_PRODUIT_DISPONIBLE
        );
    }

    /**
     * @return array{suggestions: array<int, array<string, mixed>>, disponible: bool, raison: string, option_la_moins_chere: null}
     */
    private function echec(string $raison): array
    {
        return [
            'suggestions' => [],
            'disponible' => false,
            'raison' => $raison,
            'option_la_moins_chere' => null,
        ];
    }

    /**
     * Le total n'est pas monotone en prix : rien n'impose que les frais de
     * livraison croissent avec le sous-total (`delivrery_prices` est éditable
     * en admin). Le "moins cher" à annoncer est donc celui qui minimise le
     * total facturé, jamais celui qui minimise le seul prix du plat.
     *
     * @param  array<int, Product>  $candidats
     * @return array{0: Product, 1: Quotation}|null
     */
    private function parTotalMinimal(array $candidats, Town $town): ?array
    {
        $meilleur = null;

        foreach ($candidats as $produit) {
            $quotation = $this->quotations->quote(
                [['product' => $produit, 'quantity' => 1]],
                $town
            );

            if (! $quotation->disponible) {
                continue;
            }

            if ($meilleur === null || $quotation->total < $meilleur[1]->total) {
                $meilleur = [$produit, $quotation];
            }
        }

        return $meilleur;
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
