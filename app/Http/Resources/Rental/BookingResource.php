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
            /*
             * Le statut lu, expiration comprise.
             *
             * Une reservation perimee s'annonce « expired » des la lecture,
             * sans attendre la tache planifiee : sinon l'ecran proposerait de
             * payer un acompte que le serveur refuse deja.
             */
            'status' => $this->resource->statutEffectif(),

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

            /*
             * La plaque et le chauffeur ne sortent que pendant la course.
             *
             * Meme raisonnement que pour `pickup_code` plus bas : les masquer
             * dans l'application ne masque rien, la reponse reste lisible par
             * qui detient le jeton. La plaque designe un vehicule precis sur
             * la voie publique et le chauffeur est une personne nommee, avec
             * sa photo et son telephone — ce sont deux identites reelles, pas
             * des details d'affichage.
             *
             * Elles ne servent qu'une fois la course payee, pour reconnaitre
             * la voiture qui se gare et joindre son conducteur. Avant,
             * l'acompte n'est pas arrive ; apres, la course est finie et il
             * n'y a plus rien a reconnaitre. Dans les deux cas, null.
             */
            'plate_number' => $this->courseEngagee()
                ? $this->resource->vehicle?->plate_number
                : null,

            'chauffeur' => $this->courseEngagee() && $this->resource->chauffeur
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

            /*
             * L'echeance de paiement, pour le decompte affiche au client.
             *
             * Rendue uniquement tant que la reservation attend vraiment son
             * acompte — et `statutEffectif()` tranche, pas `status` : une
             * ligne encore `pending_payment` en base mais deja depassee sort
             * d'ici en `expired`, et donnerait sinon un decompte negatif a
             * cote d'un statut qui dit le contraire.
             *
             * Sur une reservation confirmee, terminee ou annulee, la colonne
             * garde en base la valeur posee a la creation : la rendre telle
             * quelle ferait decompter une course deja payee. Comme pour la
             * plaque et le chauffeur, c'est le serveur qui decide ce qui a un
             * sens, pas l'ecran.
             */
            'expires_at' => $this->resource->statutEffectif() === Booking::STATUS_PENDING_PAYMENT
                ? $this->resource->expires_at
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

    /**
     * La course est-elle payee et pas encore finie ?
     *
     * Une seule definition, et la liste des statuts vit sur le modele : les
     * deux cles qui exposent une identite reelle — la plaque et le chauffeur
     * — la partagent. Toute autre ressource qui voudrait les rendre passe par
     * ici plutot que de recopier le test.
     */
    private function courseEngagee(): bool
    {
        return in_array($this->resource->status, Booking::COURSE_ENGAGEE, true);
    }

    private function estLeClient(Request $request): bool
    {
        return $request->user()?->id === $this->resource->user_id;
    }
}
