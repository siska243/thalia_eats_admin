<?php

namespace App\Http\Resources;

use App\Models\DelivreryPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property DelivreryPrice $resource
 */
class DelivreryPriceResource extends JsonResource
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
            'interval_pricing'=>$this->interval_pricing,
            'frais_livraison'=>$this->frais,
            'service_price'=>$this->service_price,
            'currency'=>$this->currency,
            'town'=>$this->whenLoaded('town',new TownResource($this->resource->town))
        ];
    }
}
