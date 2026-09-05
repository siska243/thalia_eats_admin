<?php

namespace App\Http\Resources;

use App\Models\Precommande;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Precommande $resource
 */
class PrecommandeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uid' => Cipher::Encrypt($this->resource->id),
            'reference' => $this->resource->refernce,
            'statut' => $this->resource->estExpiree() ? Precommande::STATUT_EXPIREE : $this->resource->status,
            'sous_total' => $this->resource->sous_total,
            'frais_livraison' => $this->resource->frais_livraison,
            'service_price' => $this->resource->service_price,
            'total' => $this->resource->total,
            'currency' => $this->resource->currency ? [
                'code' => $this->resource->currency->code,
                'slug' => $this->resource->currency->slug,
            ] : null,
            'restaurant' => $this->resource->restaurant ? [
                'name' => $this->resource->restaurant->name,
                'slug' => $this->resource->restaurant->slug,
            ] : null,
            'adresse' => $this->resource->adresse_delivery,
            'destinataire' => [
                'name' => $this->resource->recipient_name,
                'phone' => $this->resource->recipient_phone,
            ],
            'produits' => $this->whenLoaded('products', fn () => $this->resource->products->map(fn ($ligne) => [
                'uid' => Cipher::Encrypt($ligne->product_id),
                'title' => $ligne->product?->title,
                'quantity' => $ligne->quantity,
                'price' => $ligne->price,
            ])->values()),
            'expires_at' => $this->resource->expires_at,
            'created_at' => $this->resource->created_at,
            'commande' => $this->resource->commande ? [
                'uid' => Cipher::Encrypt($this->resource->commande->id),
            ] : null,
        ];
    }
}
