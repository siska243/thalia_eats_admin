<?php

namespace App\Http\Resources;

use App\Models\Commande;
use App\Models\CommandeProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Commande $resource
 */
class CommandeResource extends JsonResource
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
            'adresse' => $this->adresse_delivery,
            'reference_adresse'=>$this->reference_adresse,
            'code_confirmation' => $this->code_confirmation,
            'global_price' => $this->global_price,
            'price_delivery' => $this->price_delivery,
            'price_service'=>$this->price_service,
            'town_id' => new TownResource($this->town),
            'status_id' => $this->status,
            'reference' => $this->refernce,
            'delivrery_driver_id' => $this->delivrery_driver,
            'reception' => $this->reception,
            'products' => $this->whenLoaded('product', CommandeProductResource::collection($this->product))
        ];
    }
}
