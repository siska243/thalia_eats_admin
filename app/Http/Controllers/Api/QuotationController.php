<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteRequest;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Services\Quotation;
use App\Services\QuotationService;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotations) {}

    public function quote(QuoteRequest $request): JsonResponse
    {
        $town = Town::query()->where('slug', $request->input('town'))->first();

        if (! $town) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette ville de livraison est introuvable');
        }

        $expected_restaurant_id = null;

        if ($request->filled('restaurant')) {
            $restaurant = Restaurant::query()->where('slug', $request->input('restaurant'))->first();

            if (! $restaurant) {
                return ApiResponse::NOT_FOUND('Oups', 'Ce restaurant est introuvable');
            }

            $expected_restaurant_id = (int) $restaurant->id;
        }

        try {
            $lines = $this->resolveLines($request->input('products'));
        } catch (ModelNotFoundException $e) {
            return ApiResponse::BAD_REQUEST(
                'produit_introuvable',
                'Oups',
                'Un des produits demandés est introuvable ou n\'est plus disponible'
            );
        }

        $quotation = $this->quotations->quote($lines, $town, $expected_restaurant_id);

        return ApiResponse::GET_DATA($this->present($quotation));
    }

    /**
     * Diagnostics internes exclus : `warnings` et `bracket_id` servent à
     * l'observation côté serveur, pas aux clients.
     *
     * @return array<string, mixed>
     */
    protected function present(Quotation $quotation): array
    {
        return [
            'disponible' => $quotation->disponible,
            'sous_total' => $quotation->sous_total,
            'frais_livraison' => $quotation->frais_livraison,
            'service_price' => $quotation->service_price,
            'total' => $quotation->total,
            'currency' => $quotation->currency ? [
                'code' => $quotation->currency->code,
                'slug' => $quotation->currency->slug,
            ] : null,
            'raison' => $quotation->raison,
        ];
    }

    /**
     * @param  array<int, array{uid: string, quantity: int}>  $products
     * @return array<int, array{product: Product, quantity: int}>
     *
     * @throws ModelNotFoundException
     */
    protected function resolveLines(array $products): array
    {
        $ids = [];

        foreach ($products as $entry) {
            $id = Cipher::Decrypt($entry['uid']);

            if ($id === false || $id === '' || ! ctype_digit((string) $id)) {
                throw new ModelNotFoundException;
            }

            $ids[] = (int) $id;
        }

        $found = Product::query()
            ->with('currency')
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($products as $index => $entry) {
            $product = $found->get($ids[$index]);

            if (! $product) {
                throw new ModelNotFoundException;
            }

            $lines[] = ['product' => $product, 'quantity' => (int) $entry['quantity']];
        }

        return $lines;
    }
}
