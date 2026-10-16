<?php

namespace App\Services\Rental;

use App\Models\Currency;

/**
 * Le chiffrage d'une reservation.
 *
 * Un objet fige plutot qu'un tableau : le total, l'acompte et le solde sont
 * calcules ensemble et ne doivent pas pouvoir etre modifies un par un par
 * l'appelant. C'est le meme principe que pour les commandes de repas, le
 * montant ne vient jamais du client.
 */
readonly class BookingQuote
{
    public function __construct(
        public string $mode,
        /** Le prix d'une unite : une heure, ou une journee. */
        public float $unitPrice,
        /** Les unites facturees, entamees comprises. */
        public float $billedUnits,
        /** Le tarif horaire du vehicule, conserve a titre indicatif. */
        public float $hourlyRate,
        /** Les heures facturables du creneau, affichees quel que soit le mode. */
        public float $durationHours,
        /**
         * Le prix de la course seule, avant toute option.
         *
         * Sans ce detail, une course aeroport afficherait 90 $ la ou le
         * catalogue annonce 60, et le client croirait qu'on a change le prix
         * derriere son dos.
         */
        public float $base,
        /** Le parking aeroport facture a l'heure, zero sinon. */
        public float $parkingFee,
        public float $total,
        public float $deposit,
        public float $balance,
        public Currency $currency,
    ) {}

    /** @return array<string, mixed> Les colonnes d'une reservation. */
    public function toBookingAttributes(): array
    {
        return [
            'pricing_mode' => $this->mode,
            'unit_price' => $this->unitPrice,
            'billed_units' => $this->billedUnits,
            'hourly_rate' => $this->hourlyRate,
            'duration_hours' => $this->durationHours,
            'base_price' => $this->base,
            'parking_fee' => $this->parkingFee,
            'total' => $this->total,
            'deposit' => $this->deposit,
            'balance' => $this->balance,
            'currency_id' => $this->currency->id,
        ];
    }
}
