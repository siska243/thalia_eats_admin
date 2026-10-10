<?php

namespace App\Http\Resources\Rental;

use App\Models\Booking;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une course, telle que son chauffeur la voit.
 *
 * Distincte de `BookingResource`, qui est ecrite pour le client : les deux
 * metiers ne regardent pas la meme chose. Le client veut savoir quelle voiture
 * vient et combien il paie ; le chauffeur veut savoir qui il prend, ou, quand,
 * et ce qui reste a encaisser.
 *
 * Deux choses ne sortent jamais d'ici :
 *
 * - le **code de prise en charge**, que le client garde. Le chauffeur le
 *   saisit, le serveur compare. Le lui rendre lui permettrait de declarer une
 *   course qu'il n'a pas faite, exactement la faille corrigee sur les
 *   livraisons de repas ;
 * - l'**adresse de domicile** du client et tout ce qui ne sert pas la course.
 *
 * @property Booking $resource
 */
class CourseChauffeurResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uid' => Cipher::Encrypt($this->resource->id),
            'reference' => $this->resource->reference,

            // Le statut lu, expiration comprise : meme regle que cote client,
            // une seule definition sur le modele.
            'status' => $this->resource->statutEffectif(),

            'starts_at' => $this->resource->starts_at,
            'ends_at' => $this->resource->ends_at,
            'duration_hours' => (float) $this->resource->duration_hours,

            /*
             * Le mode et les unites facturees, pour que l'ecran puisse ecrire
             * « 2 jours » la ou le client a reserve a la journee.
             *
             * C'est le serveur qui a arrondi les heures et les journees
             * entamees. Les deriver dans l'application — diviser les heures
             * par 24 — ferait afficher au chauffeur une duree differente de
             * celle qui a ete facturee, sur la meme course.
             */
            'pricing_mode' => $this->resource->pricing_mode,
            'billed_units' => $this->resource->billed_units !== null
                ? (float) $this->resource->billed_units
                : null,

            'pickup_location' => $this->resource->pickup_location,
            'pickup_lat' => $this->resource->pickup_lat,
            'pickup_lng' => $this->resource->pickup_lng,
            'dropoff_location' => $this->resource->dropoff_location,
            'dropoff_lat' => $this->resource->dropoff_lat,
            'dropoff_lng' => $this->resource->dropoff_lng,

            /*
             * Le client : de quoi le reconnaitre et l'appeler, rien de plus.
             *
             * Il n'est rendu qu'une fois la course engagee, comme la plaque et
             * le chauffeur le sont cote client. Une course encore en attente
             * de paiement n'a pas a livrer le telephone de quelqu'un a un
             * chauffeur qui ne la fera peut-etre jamais.
             */
            'client' => $this->courseEngagee()
                ? [
                    'full_name' => trim(
                        ($this->resource->user?->name ?? '').' '.($this->resource->user?->last_name ?? '')
                    ) ?: null,
                    'phone' => $this->resource->user?->phone,
                ]
                : null,

            'vehicle' => new VehicleResource($this->resource->vehicle),

            // Sa propre voiture : la plaque lui sert a la reconnaitre au
            // depot. Elle suit la meme regle que cote client.
            'plate_number' => $this->courseEngagee()
                ? $this->resource->vehicle?->plate_number
                : null,

            /*
             * Les montants, parce que le solde peut lui etre remis en
             * especes. Ce sont ceux du serveur, jamais recalcules.
             */
            'total' => (float) $this->resource->total,
            'deposit' => (float) $this->resource->deposit,
            'balance' => (float) $this->resource->balance,
            'currency' => $this->resource->currency,

            'confirmed_at' => $this->resource->confirmed_at,
            'picked_up_at' => $this->resource->picked_up_at,
            'completed_at' => $this->resource->completed_at,
            'cancelled_at' => $this->resource->cancelled_at,
        ];
    }

    /** Payee et pas encore finie : la meme liste que cote client. */
    private function courseEngagee(): bool
    {
        return in_array($this->resource->statutEffectif(), Booking::COURSE_ENGAGEE, true);
    }
}
