<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Town;
use App\Wrappers\Cipher;

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
            "uid" => Cipher::Encrypt($this->id),
            "slug" => $this->slug,
            'title' => $this->resource->title,
        ];
    }
}
