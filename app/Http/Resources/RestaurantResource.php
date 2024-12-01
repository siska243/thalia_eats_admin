<?php

namespace App\Http\Resources;

use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


/**
 * @property Restaurant $resource
 */
class RestaurantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);

        $host = $request->server('HTTP_HOST');
        return [
            'name' => $this->resource->name,
            'adresse' => $this->resource->adresse,
            'slug' => $this->resource->slug,
            'description'=>$this->resource->description,
            'email'=> $this->resource->email,
            'opens' => $this->resource->openHours,
            'reference' => $this->resource->reference,
            'phone' => $this->resource->phone,
            'whatsapp' => $this->resource->whatsapp,
            'location'=> $this->resource->location,
            'commune' => $this->whenNotNull(new TownResource($this->resource->town)),
            'image' => 'https://' . $host . '/images/' . $this->resource->banniere
        ];
    }
}
