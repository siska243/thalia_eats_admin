<?php

namespace App\Http\Resources;

use App\Models\CommandeProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property CommandeProduct $resource
 */
class CommandeProductResource extends JsonResource
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
            'price'=>$this->price,
            'quantity'=>$this->quantity,
            'currency'=>$this->currency,
            'product'=>new ProductResource($this->product)
        ];
    }
}
