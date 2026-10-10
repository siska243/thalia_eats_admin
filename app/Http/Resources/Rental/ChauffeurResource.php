<?php

namespace App\Http\Resources\Rental;

use App\Helpers\ImageUrl;
use App\Models\Chauffeur;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Le chauffeur, tel que le client le voit.
 *
 * Nom, photo et telephone : de quoi reconnaitre la personne qui vient et la
 * joindre. Rien d'autre — ni son permis, ni son compte, ni ses autres courses.
 *
 * @property Chauffeur $resource
 */
class ChauffeurResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'full_name' => $this->resource->full_name,
            'phone' => $this->resource->phone,
            'photo' => $this->resource->photo
                ? ImageUrl::make($request, $this->resource->photo)
                : null,
        ];
    }
}
