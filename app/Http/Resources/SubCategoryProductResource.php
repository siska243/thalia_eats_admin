<?php

namespace App\Http\Resources;

use App\Models\SubCategoryProduct;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property SubCategoryProduct $resource
 */
class SubCategoryProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        //return parent::toArray($request);
        return [
            'uid'=>Cipher::Encrypt($this->id),
            'title'=>$this->resource->title,
            'slug'=>$this->slug,
            'picture'=>'https://'.$request->server('HTTP_HOST')."/images/".$this->resource->picture,
            'product'=>$this->whenLoaded('product', ProductResource::collection($this->product)->where('is_active',true)),
        ];
    }
}
