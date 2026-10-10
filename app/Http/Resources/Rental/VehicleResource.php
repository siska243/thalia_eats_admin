<?php

namespace App\Http\Resources\Rental;

use App\Helpers\ImageUrl;
use App\Models\Vehicle;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Vehicle $resource
 */
class VehicleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uid' => Cipher::Encrypt($this->resource->id),
            'reference' => $this->resource->reference,
            'slug' => $this->resource->slug,
            'name' => $this->resource->name,
            'brand' => $this->resource->brand,
            'model' => $this->resource->model,
            'seats' => $this->resource->seats,
            'description' => $this->resource->description,
            'image' => $this->resource->image
                ? ImageUrl::make($request, $this->resource->image)
                : null,
            'hourly_rate' => (float) $this->resource->hourly_rate,
            /*
             * Nul quand le vehicule n'est propose qu'a l'heure. L'ecran
             * n'affiche alors pas le mode journee, plutot qu'un prix a zero.
             */
            'daily_rate' => $this->resource->daily_rate !== null
                ? (float) $this->resource->daily_rate
                : null,
            'currency' => $this->resource->currency,

            /*
             * La plaque n'est pas exposee au catalogue : elle identifie un
             * vehicule precis sur la voie publique, et le client n'en a besoin
             * qu'une fois sa reservation confirmee. Elle figure alors sur la
             * reservation.
             */
        ];
    }
}
