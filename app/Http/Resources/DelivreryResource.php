<?php

namespace App\Http\Resources;

use App\Models\DelivreryDriver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property DelivreryDriver $resource
 */
class DelivreryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_card'=>$this->resource->id_card,
            'birth_date'=>$this->resource->birth_date,
            'user'=>$this->whenLoaded('user',new UserResource($this->resource->user)),
        ];
    }
}
