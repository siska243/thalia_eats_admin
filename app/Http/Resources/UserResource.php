<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property User $resource
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [

            'email' => $this->resource->email,
            'uid' => Cipher::Encrypt($this->resource->id),
            'last_name' => $this->resource->last_name,
            'name' => (string)$this->resource->name,
            'full_name' => $this->resource->last_name . ' ' . $this->resource->name,
            'phone' => (string)$this->resource->phone,
            'principal_adresse' => $this->resource->principal_adresse,
            'street'=>$this->resource->street,
            'number_street'=>$this->resource->number_street,
            'town_id' => $this->resource->town,
            'slug' => $this->resource->slug,
        ];
    }
}
