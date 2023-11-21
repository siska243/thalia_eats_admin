<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


/**
 * @property Product $resource
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);
        $host=$request->server('HTTP_HOST');
        return [
            'uid'=>Cipher::Encrypt($this->id),
            'title'=>$this->title,
            'description'=>$this->description,
            'price'=>$this->price,
            'currency'=>$this->currency,
            'promotionnalPrice'=>$this->promotionnalPrice,
            'slug'=>$this->slug,
            'picture'=>'https://'.$host.'/images/'.$this->picture,
            "is_promotional"=>$this->is_promotional,
            "is_in_forward"=>$this->is_in_forward,
            'restaurant'=>$this->restaurant
        ];
    }
}
