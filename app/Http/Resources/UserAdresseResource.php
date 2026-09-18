<?php

namespace App\Http\Resources;

use App\Models\UserAdresse;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property UserAdresse $resource
 */
class UserAdresseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uid' => Cipher::Encrypt($this->resource->id),
            'slug' => $this->resource->slug,
            'label' => $this->resource->label,
            'adresse' => $this->resource->adresse,
            'street' => $this->resource->street,
            'number_street' => $this->resource->number_street,
            'reference' => $this->resource->reference,
            'lat' => $this->resource->lat,
            'long' => $this->resource->long,
            'is_main' => (bool) $this->resource->is_main,
            'town' => $this->whenNotNull(new TownResource($this->resource->town)),
        ];
    }
}
