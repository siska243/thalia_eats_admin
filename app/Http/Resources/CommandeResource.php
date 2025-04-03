<?php

namespace App\Http\Resources;

use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Wrappers\Cipher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @property Commande $resource
 */
class CommandeResource extends JsonResource
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
            'uid'=>Cipher::encrypt($this->resource->id),
            'adresse' => $this->resource->adresse_delivery,
            'reference_adresse'=>$this->resource->reference_adresse,
            'code_confirmation' => $this->resource->code_confirmation,
            'restaurant_code_confirmation' => $this->resource->code_confirmation_restaurant,
            "address_delivery"=> $this->resource->adresse_delivery,
            "street"=>$this->resource->street,
            "number_street"=>$this->resource->number_street,
            'delivery_at'=>$this->resource->delivery_at,
            'cancel_at'=>$this->resource->cancel_at,
            'accepted_at'=>$this->resource->accepted_at,
            'global_price' => $this->resource->global_price,
            'price_delivery' => $this->resource->price_delivery,
            'price_service'=>$this->resource->price_service,
            'town_id' => new TownResource($this->resource->town),
            'status' => new StatusResource ($this->resource->status),
            'reference' => $this->resource->refernce,
            'reference_address' => $this->resource->adresse,
            'delivrery_driver_id' => $this->whenLoaded('delivrery_driver',new DelivreryResource($this->resource->delivrery_driver)),
            'reception' => $this->resource->reception,
            'user'=>$this->whenLoaded('user',new UserResource($this->resource->user)),
            "reference_paiement"=>$this->resource->reference_paiement,
            'time_restaurant'=>$this->resource->time_restaurant,
            'time_delivery'=>$this->resource->time_delivery,
            'date_time_restautant'=>$this->resource->time_restaurant ?? self::customTime($this->resource->accepted_at,$this->resource->time_restaurant),
            'date_time_delivery'=>$this->resource->time_delivery ?? self::customTime($this->resource->accepted_at,$this->resource->time_delivery),
            'products' => $this->whenLoaded('product', CommandeProductResource::collection($this->resource->product)),
            'user_delivery_complet_adress'=>"{$this->resource->adresse_delivery}, {$this->resource->reference_adresse}, {$this->resource->town?->title}",
            "mask_address"=>Str::mask("{$this->resource->adresse_delivery}, {$this->resource->reference_adresse}, {$this->resource->town?->title}","*",3)
        ];
    }

    public static function customTime($date,$time)
    {
        $date = Carbon::parse($date);
        $time = Carbon::parse($time);

        $date->setTime($time->hour, $time->minute, $time->second);

        return $date->toDateTimeString();
    }
}
