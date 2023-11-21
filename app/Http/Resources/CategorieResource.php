<?php

namespace App\Http\Resources;

use App\Models\CategoryProduct;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property CategoryProduct $resource
 */
class CategorieResource extends JsonResource
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
            'uid'=>Cipher::Encrypt($this->resource->id),
            'title'=>$this->resource->title,
            'slug'=> $this->resource->slug,
            'sub_category_product'=>SubCategoryProductResource::collection($this->whenLoaded('sub_category_product'))
        ];
    }
}
