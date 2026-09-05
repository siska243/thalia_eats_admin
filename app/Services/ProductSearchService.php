<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ProductSearchService
{
    /**
     * innodb_ft_min_token_size vaut 3 par défaut : les mots plus courts ne sont
     * pas indexés. On bascule alors sur un LIKE.
     */
    private const MIN_TOKEN_SIZE = 3;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{paginator: LengthAwarePaginator, distances: array<int, float|null>}
     */
    public function search(array $filters): array
    {
        ['ids' => $ids, 'distances' => $distances] = $this->eligibleRestaurants($filters);

        $query = Product::query()
            ->with(['currency', 'restaurant', 'sub_category_product'])
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->whereIn('products.restaurant_id', $ids === [] ? [0] : $ids);

        $this->applyText($query, $filters['q'] ?? null);
        $this->applyFilters($query, $filters);
        $this->applyOrder($query, $filters, $ids);

        $per_page = min(max((int) ($filters['per_page'] ?? 20), 1), 50);

        return [
            'paginator' => $query->paginate($per_page),
            'distances' => $distances,
        ];
    }

    /**
     * Restaurants éligibles, ordonnés : les géolocalisés par distance croissante,
     * puis ceux sans coordonnées.
     *
     * @param  array<string, mixed>  $filters
     * @return array{ids: array<int, int>, distances: array<int, float|null>}
     */
    public function eligibleRestaurants(array $filters): array
    {
        $query = Restaurant::query()
            ->where('is_active', true)
            ->whereNull('deleted_at');

        // On ne filtre jamais sur une donnée absente : un restaurant sans town
        // reste éligible, sinon un parc mal renseigné devient invisible.
        if (! empty($filters['town_id'])) {
            $town_id = (int) $filters['town_id'];
            $query->where(fn (Builder $q) => $q->whereNull('town_id')->orWhere('town_id', $town_id));
        }

        $restaurants = $query->get(['id', 'name', 'location']);

        $lat = isset($filters['lat']) ? (float) $filters['lat'] : null;
        $lng = isset($filters['lng']) ? (float) $filters['lng'] : null;
        $radius = isset($filters['radius']) ? (float) $filters['radius'] : null;

        $geolocalises = [];
        $sans_position = [];
        $distances = [];

        foreach ($restaurants as $restaurant) {
            $id = (int) $restaurant->id;
            $coords = ($lat !== null && $lng !== null)
                ? RestaurantGeo::coordinates($restaurant->location)
                : null;

            if ($coords === null) {
                $distances[$id] = null;
                $sans_position[] = ['id' => $id, 'name' => (string) $restaurant->name];

                continue;
            }

            $distance = RestaurantGeo::distanceKm($lat, $lng, $coords['lat'], $coords['lng']);

            // Le rayon n'exclut que les restaurants dont on connaît la position.
            if ($radius !== null && $distance > $radius) {
                continue;
            }

            $distances[$id] = $distance;
            $geolocalises[] = ['id' => $id, 'distance' => $distance];
        }

        usort($geolocalises, fn ($a, $b) => $a['distance'] <=> $b['distance']);
        usort($sans_position, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $ids = array_merge(
            array_column($geolocalises, 'id'),
            array_column($sans_position, 'id')
        );

        return ['ids' => $ids, 'distances' => $distances];
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applyText(Builder $query, ?string $q): void
    {
        $q = trim((string) $q);

        if ($q === '') {
            return;
        }

        // On retire les opérateurs du mode booléen pour qu'une saisie utilisateur
        // ne puisse pas construire une expression MySQL involontaire.
        $tokens = preg_split('/\s+/', preg_replace('/[+\-><()~*"@]+/', ' ', $q)) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));

        if ($tokens === []) {
            return;
        }

        $indexables = array_filter($tokens, fn ($t) => mb_strlen($t) >= self::MIN_TOKEN_SIZE);

        if ($indexables === []) {
            $query->where(function (Builder $sub) use ($tokens) {
                foreach ($tokens as $token) {
                    $sub->orWhere('products.title', 'like', '%'.$token.'%');
                }
            });

            return;
        }

        $expression = implode(' ', array_map(fn ($t) => '+'.$t.'*', $indexables));

        $query->whereRaw(
            'MATCH(products.title, products.description) AGAINST (? IN BOOLEAN MODE)',
            [$expression]
        );
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['currency_id'])) {
            $query->where('products.currency_id', (int) $filters['currency_id']);
        }

        if (isset($filters['price_min'])) {
            $query->where('products.price', '>=', (float) $filters['price_min']);
        }

        if (isset($filters['price_max'])) {
            $query->where('products.price', '<=', (float) $filters['price_max']);
        }

        if (! empty($filters['sub_category'])) {
            $query->whereHas('sub_category_product', fn (Builder $q) => $q->where('slug', $filters['sub_category']));
        }

        if (! empty($filters['category'])) {
            // sub_category_products.category_product_id, verifie sur la base reelle.
            $query->whereHas(
                'sub_category_product.category_product',
                fn (Builder $q) => $q->where('slug', $filters['category'])
            );
        }
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     * @param  array<int, int>  $ids
     */
    private function applyOrder(Builder $query, array $filters, array $ids): void
    {
        if (($filters['sort'] ?? 'prix') === 'distance' && $ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query->orderByRaw("FIELD(products.restaurant_id, $placeholders)", $ids);
        }

        $query->orderBy('products.price')->orderBy('products.id');
    }
}
