<?php

namespace App\Services;

/**
 * Le verdict d'une portee de livraison.
 *
 * Un objet fige plutot qu'un tableau, comme `Quotation` et `BookingQuote` : le
 * verdict, la distance et le message sont calcules ensemble et ne doivent pas
 * pouvoir etre modifies un par un par l'appelant.
 */
readonly class Portee
{
    public function __construct(
        public bool $autorise,
        /** La distance en kilometres, ou null si une des deux positions manque. */
        public ?float $distance_km = null,
        /** Le rayon applique, ou null quand aucun controle n'etait possible. */
        public ?float $rayon_km = null,
        /** La phrase montree au client. Null quand c'est autorise. */
        public ?string $message = null,
    ) {}

    /** Aucune position exploitable : on laisse passer. */
    public static function sansControle(): self
    {
        return new self(true);
    }
}
