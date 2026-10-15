<?php

namespace App\Http\Resources;

use App\Helpers\ImageUrl;
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

        return [
            'name' => $this->resource->name,
            'adresse' => $this->resource->adresse,
            'slug' => $this->resource->slug,
            'description' => $this->resource->description,
            'email' => $this->resource->email,
            'opens' => $this->resource->openHours,
            'reference' => $this->resource->reference,
            'phone' => $this->resource->phone,
            'whatsapp' => $this->resource->whatsapp,
            'location' => $this->resource->location,
            'commune' => $this->whenNotNull(new TownResource($this->resource->town)),

            /*
             * La portee de livraison, quand elle a pu etre calculee.
             *
             * `livrable` vaut vrai par defaut : un client qui n'envoie pas sa
             * position voit le catalogue d'avant, inchange. C'est la condition
             * pour que les applications deja installees ne changent pas de
             * comportement du jour au lendemain.
             */
            'distance_km' => $this->resource->portee?->distance_km,
            'livrable' => $this->resource->portee?->autorise ?? true,
            'portee_message' => $this->resource->portee?->message,
            'image' => ImageUrl::make($request, $this->resource->banniere),
        ];
    }
}
