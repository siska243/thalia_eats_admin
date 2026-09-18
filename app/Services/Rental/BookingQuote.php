<?php

namespace App\Services\Rental;

use App\Models\Currency;

/**
 * Le chiffrage d'une reservation.
 *
 * Un objet fige plutot qu'un tableau : le total, l'acompte et le solde sont
 * calcules ensemble et ne doivent pas pouvoir etre modifies un par un par
 * l'appelant. C'est le meme principe que pour les commandes de repas — le
 * montant ne vient jamais du client.
 */
readonly class BookingQuote
{
    public function __construct(
        public float $hourlyRate,
        public float $durationHours,
        public float $total,
        public float $deposit,
        public float $balance,
        public Currency $currency,
    ) {
    }

    /** @return array<string, mixed> Les colonnes d'une reservation. */
    public function toBookingAttributes(): array
    {
        return [
            'hourly_rate' => $this->hourlyRate,
            'duration_hours' => $this->durationHours,
            'total' => $this->total,
            'deposit' => $this->deposit,
            'balance' => $this->balance,
            'currency_id' => $this->currency->id,
        ];
    }
}
