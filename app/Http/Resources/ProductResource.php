<?php

namespace App\Http\Resources;

use App\Helpers\ImageUrl;
use App\Models\Product;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Product $resource
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);
        return [
            'uid' => Cipher::Encrypt($this->id),
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'promotionnalPrice' => $this->promotionnalPrice,
            'slug' => $this->slug,
            'picture' => ImageUrl::make($request, $this->picture),
            'is_promotional' => $this->is_promotional,
            'is_in_forward' => $this->is_in_forward,
            /*
             * Le stock, tel que les applications doivent le lire.
             *
             * `stock_disponible` a null veut dire « non suivi » — ni compteur
             * a afficher, ni limite a opposer. C'est l'etat de tout le
             * catalogue d'aujourd'hui, et l'ecran doit alors se comporter
             * exactement comme avant.
             *
             * `epuise` est calcule ICI et non dans les applications : la regle
             * vit sur `Product::estEpuise()`, une seule fois. Deux clients qui
             * la recopient finissent par dire le contraire l'un de l'autre, et
             * c'est deja arrive sur les horaires d'ouverture.
             */
            'stock_disponible' => $this->stock_quantity,
            'epuise' => $this->resource->estEpuise(),
            'restaurant' => new RestaurantResource($this->resource->restaurant),
        ];
    }
}
