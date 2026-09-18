<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Currency;
use App\Models\Town;
use App\Services\ProductSearchService;
use App\Wrappers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSearchController extends Controller
{
    public function __construct(private readonly ProductSearchService $search) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string'],
            'sub_category' => ['nullable', 'string'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string'],
            'town' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', 'in:prix,distance'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $filters = $validated;

        if (! empty($validated['town'])) {
            $town = Town::query()->where('slug', $validated['town'])->first();

            if (! $town) {
                return ApiResponse::NOT_FOUND('Oups', 'Cette ville est introuvable');
            }

            $filters['town_id'] = $town->id;
        }

        if (! empty($validated['currency'])) {
            $currency = Currency::parSlugOuCode($validated['currency']);

            if (! $currency) {
                return ApiResponse::NOT_FOUND('Oups', 'Cette devise est introuvable');
            }

            $filters['currency_id'] = $currency->id;
        }

        ['paginator' => $paginator, 'distances' => $distances] = $this->search->search($filters);

        $data = collect($paginator->items())->map(fn ($product) => [
            'product' => new ProductResource($product),
            'distance_km' => $distances[(int) $product->restaurant_id] ?? null,
        ])->values();

        return ApiResponse::GET_DATA([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
