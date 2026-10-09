<?php

namespace App\Http\Resources\Rental;

use App\Models\Booking;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Booking $resource
 */
class BookingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uid' => Cipher::Encrypt($this->resource->id),
            'reference' => $this->resource->reference,
            'status' => $this->resource->status,

            'starts_at' => $this->resource->starts_at,
            'ends_at' => $this->resource->ends_at,
            'duration_hours' => (float) $this->resource->duration_hours,

            'pickup_location' => $this->resource->pickup_location,
            'pickup_lat' => $this->resource->pickup_lat,
            'pickup_lng' => $this->resource->pickup_lng,
            'dropoff_location' => $this->resource->dropoff_location,
            'dropoff_lat' => $this->resource->dropoff_lat,
            'dropoff_lng' => $this->resource->dropoff_lng,

            /*
             * Le mode applique et son prix unitaire. Le client doit relire sur
             * sa reservation ce qu'il a choisi : « 2 journees a 149 » et non
             * un total sans explication.
             */
            'pricing_mode' => $this->resource->pricing_mode,
            'unit_price' => $this->resource->unit_price !== null
                ? (float) $this->resource->unit_price
                : null,
            'billed_units' => $this->resource->billed_units !== null
                ? (float) $this->resource->billed_units
                : null,

            'hourly_rate' => (float) $this->resource->hourly_rate,
            'total' => (float) $this->resource->total,
            'deposit' => (float) $this->resource->deposit,
            'balance' => (float) $this->resource->balance,
            'currency' => $this->resource->currency,

            'vehicle' => new VehicleResource($this->resource->vehicle),

            // La plaque arrive ici, une fois la reservation faite : c'est a ce
            // moment qu'elle sert, pour reconnaitre la voiture qui se gare.
            'plate_number' => $this->resource->vehicle?->plate_number,

            'chauffeur' => $this->resource->chauffeur
                ? new ChauffeurResource($this->resource->chauffeur)
                : null,

            /*
             * Le code de prise en charge n'est montre qu'au client, et
             * seulement une fois la reservation payee.
             *
             * Le modele le masque par defaut : on le ressort ici, pour son
             * proprietaire uniquement. Le chauffeur ne le recoit jamais — il
             * le saisit, le serveur compare. Sans cette regle il pourrait
             * declarer une course qu'il n'a pas faite, exactement la faille
             * corrigee sur les livraisons de repas.
             */
            'pickup_code' => $this->estLeClient($request)
                ? $this->resource->pickup_code
                : null,

            'confirmed_at' => $this->resource->confirmed_at,
            'picked_up_at' => $this->resource->picked_up_at,
            'completed_at' => $this->resource->completed_at,
            'cancelled_at' => $this->resource->cancelled_at,
            'cancellation_reason' => $this->resource->cancellation_reason,
            'refund_amount' => $this->resource->refund_amount !== null
                ? (float) $this->resource->refund_amount
                : null,
            'refunded_at' => $this->resource->refunded_at,
        ];
    }

    private function estLeClient(Request $request): bool
    {
        return $request->user()?->id === $this->resource->user_id;
    }
}
