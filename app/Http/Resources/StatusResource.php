<?php

namespace App\Http\Resources;

use App\Helpers\Utils;
use App\Models\Restaurant;
use App\Models\Status;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use phpDocumentor\Reflection\Types\Resource_;

/**
 * @property Status $resource
 */
class StatusResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'render' => Utils::getIcon($this->resource->icon, $this->resource->color),
            'name' => $this->resource->title,
            'color' => $this->resource->color,
            'icon' => $this->resource->icon,
            'uid'=>Cipher::Encrypt($this->resource->id),
        ];
    }
}
