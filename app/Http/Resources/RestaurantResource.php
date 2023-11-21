<?php

namespace App\Http\Resources;

use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Restaurantant $resource
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
            'opens' => $this->openHours,
            'reference' => $this->reference,
            'phone' => $this->phone,
            'commune' => $this->whenNotNull($this->town_id),
            'image' => 'https://' . $host . '/images/' . $this->resource->banniere
        ];
    }
}
