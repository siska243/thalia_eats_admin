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
            /*
             * La valeur par defaut de whenLoaded etait evaluee immediatement
             * par PHP : $this->product declenchait un chargement paresseux de
             * TOUS les produits de la sous-categorie, sans contrainte de
             * restaurant, alors meme que le controleur n'avait pas precharge
             * la relation. La carte d'un restaurant remontait ainsi les plats
             * d'un autre.
             *
             * Avec une closure, rien n'est evalue tant que la relation n'est
             * pas chargee : c'est desormais au controleur de la precharger
             * avec les contraintes qui conviennent a son contexte.
             */
            'product'=>$this->whenLoaded('product', fn () => ProductResource::collection(
                $this->resource->product->where('is_active', true)->values()
            )),
        ];
    }
}
