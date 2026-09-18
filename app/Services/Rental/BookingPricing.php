<?php

namespace App\Services\Rental;

use App\Models\Vehicle;
use App\Settings\RentalSettings;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Le prix d'une reservation, calcule par le serveur.
 *
 * Seule implementation : le client envoie un creneau, jamais un montant. La
 * meme doctrine que `QuotationService` pour les repas, ou laisser le prix
 * venir du client avait ouvert une faille de paiement.
 */
class BookingPricing
{
    public function __construct(private readonly RentalSettings $settings)
    {
    }

    public function quote(Vehicle $vehicle, Carbon $startsAt, Carbon $endsAt): BookingQuote
    {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('La fin doit suivre le debut.');
        }

        $hours = $this->billableHours($startsAt, $endsAt);
        $rate = (float) $vehicle->hourly_rate;

        $total = round($rate * $hours, 2);
        $deposit = round($total * $this->settings->deposit_percentage / 100, 2);

        return new BookingQuote(
            hourlyRate: $rate,
            durationHours: $hours,
            total: $total,
            deposit: $deposit,
            // Par soustraction, et non par un second pourcentage : deux
            // arrondis independants ne se rejoignent pas toujours, et le
            // client paierait un centime de trop ou de moins.
            balance: round($total - $deposit, 2),
            currency: $vehicle->currency,
        );
    }

    /**
     * Les heures facturees.
     *
     * Toute heure entamee est due, et une course ne descend pas sous le
     * minimum configure. Une reservation de 10h00 a 15h30 compte six heures,
     * pas cinq et demie.
     */
    public function billableHours(Carbon $startsAt, Carbon $endsAt): float
    {
        $minutes = $startsAt->diffInMinutes($endsAt);

        return max(
            $this->settings->minimum_hours,
            (float) ceil($minutes / 60),
        );
    }

    public function refundFor(float $amountPaid): float
    {
        return round($amountPaid * $this->settings->refund_percentage / 100, 2);
    }
}
