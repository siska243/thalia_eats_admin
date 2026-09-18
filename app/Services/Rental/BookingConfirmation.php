<?php

namespace App\Services\Rental;

use App\Models\Booking;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Attribue — ou refuse — un vehicule au moment ou l'acompte arrive.
 *
 * C'est le point le plus delicat du module. Une reservation non payee ne
 * retient rien : plusieurs clients peuvent viser le meme creneau en meme
 * temps, et l'argent est debite avant que le serveur sache qui gagne.
 *
 * Sans verrou, deux webhooks arrivant a la meme seconde liraient tous deux un
 * creneau libre et confirmeraient tous deux : le vehicule serait vendu deux
 * fois, et personne ne s'en apercevrait avant le jour de la course. On
 * serialise donc par vehicule — `lockForUpdate` sur sa ligne — le temps de
 * relire les conflits et d'ecrire la decision.
 *
 * Le perdant n'est pas efface : il passe en `lost` avec le montant a
 * rembourser, seule trace permettant de rendre l'argent.
 */
class BookingConfirmation
{
    public function __construct(
        private readonly VehicleAvailability $availability,
        private readonly BookingPricing $pricing,
    ) {
    }

    /**
     * L'acompte est arrive. Le creneau est-il encore libre ?
     *
     * @return bool Vrai si la reservation est confirmee, faux si elle est perdue.
     */
    public function settleDeposit(Booking $booking, float $amountPaid): bool
    {
        return DB::transaction(function () use ($booking, $amountPaid) {
            /*
             * Le verrou porte sur le vehicule, pas sur la reservation : c'est
             * la ressource disputee. Verrouiller la reservation laisserait
             * deux candidats different passer cote a cote.
             */
            Vehicle::query()->whereKey($booking->vehicle_id)->lockForUpdate()->first();

            $booking->refresh();

            $free = $this->availability->conflictsFor(
                $booking->vehicle,
                $booking->starts_at,
                $booking->ends_at,
                $booking,
            )->doesntExist();

            if (! $free) {
                $booking->update([
                    'status' => Booking::STATUS_LOST,
                    'refund_amount' => $this->pricing->refundFor($amountPaid),
                ]);

                return false;
            }

            $booking->update([
                'status' => Booking::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                // Genere seulement maintenant : avant le paiement, il n'y a
                // rien a prouver, et un code qui circule tot est un code de
                // plus a proteger.
                'pickup_code' => $this->pickupCode(),
            ]);

            return true;
        });
    }

    private function pickupCode(): string
    {
        return Str::padLeft((string) random_int(0, 9999), 4, '0');
    }
}
