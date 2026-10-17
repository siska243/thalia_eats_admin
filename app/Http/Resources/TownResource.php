<?php

namespace App\Http\Resources;

use App\Models\Town;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Town $resource
 */
class TownResource extends JsonResource
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
            'slug' => $this->slug,
            'title' => $this->resource->title,

            /*
             * Le centre de la commune.
             *
             * Il existait en base et en back-office depuis la limite de
             * distance, mais n'atteignait pas les applications. Sans lui,
             * l'ecran de nouvelle adresse devait deviner la commune a partir
             * du nom rendu par le geocodeur — et sur Android celui-ci ne
             * rend souvent aucun district, seulement « Kinshasa » a tous les
             * niveaux. Comme une commune porte aussi ce nom, toute adresse
             * kinoise tombait sur elle.
             *
             * Avec les coordonnees, l'application prend la commune la plus
             * proche du point pose : la question ne se pose plus.
             */
            'latitude' => $this->resource->latitude !== null ? (float) $this->resource->latitude : null,
            'longitude' => $this->resource->longitude !== null ? (float) $this->resource->longitude : null,
        ];
    }
}
