<?php

namespace App\Services\Rental;

use App\Models\Vehicle;
use App\Settings\RentalSettings;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Le prix d'une reservation, calcule par le serveur.
 *
 * Seule implementation : le client envoie un creneau et un mode, jamais un
 * montant. La meme doctrine que `QuotationService` pour les repas, ou laisser
 * le prix venir du client avait ouvert une faille de paiement.
 *
 * Deux modes, et aucun melange. A l'heure, toute heure entamee est due ; a la
 * journee, toute journee entamee l'est. Une course de trente heures coute donc
 * deux journees si elle est reservee a la journee, et trente heures si elle
 * l'est a l'heure. C'est au client de choisir, et c'est lui qui voit les deux
 * prix avant de trancher.
 */
class BookingPricing
{
    public const MODE_HOURLY = 'hourly';

    public const MODE_DAILY = 'daily';

    public const MODES = [self::MODE_HOURLY, self::MODE_DAILY];

    /** Une journee de location. */
    private const HEURES_PAR_JOUR = 24;

    public function __construct(private readonly RentalSettings $settings) {}

    public function quote(
        Vehicle $vehicle,
        Carbon $startsAt,
        Carbon $endsAt,
        string $mode = self::MODE_HOURLY,
    ): BookingQuote {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('La fin doit suivre le debut.');
        }

        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Mode de tarification inconnu : {$mode}.");
        }

        if ($mode === self::MODE_DAILY && ! $this->estProposeALaJournee($vehicle)) {
            throw new InvalidArgumentException("Ce vehicule n'est pas propose a la journee.");
        }

        $unitPrice = $mode === self::MODE_DAILY
            ? (float) $vehicle->daily_rate
            : (float) $vehicle->hourly_rate;

        $units = $mode === self::MODE_DAILY
            ? $this->billableDays($startsAt, $endsAt)
            : $this->billableHours($startsAt, $endsAt);

        $total = round($unitPrice * $units, 2);
        $deposit = round($total * $this->settings->deposit_percentage / 100, 2);

        return new BookingQuote(
            mode: $mode,
            unitPrice: $unitPrice,
            billedUnits: $units,
            hourlyRate: (float) $vehicle->hourly_rate,
            // Les heures facturables, quel que soit le mode : c'est ce que la
            // colonne signifie depuis le depart et ce que les ecrans
            // affichent. Les unites reellement facturees sont a part.
            durationHours: $this->billableHours($startsAt, $endsAt),
            total: $total,
            deposit: $deposit,
            // Par soustraction, et non par un second pourcentage : deux
            // arrondis independants ne se rejoignent pas toujours, et le
            // client paierait un centime de trop ou de moins.
            balance: round($total - $deposit, 2),
            currency: $vehicle->currency,
        );
    }

    /** Un vehicule sans tarif journalier n'est propose qu'a l'heure. */
    public function estProposeALaJournee(Vehicle $vehicle): bool
    {
        return $vehicle->daily_rate !== null && (float) $vehicle->daily_rate > 0;
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

    /**
     * Les journees facturees.
     *
     * Toute journee entamee est due, et jamais moins d'une : vingt-cinq heures
     * comptent deux journees. Le minimum horaire ne s'applique pas ici, une
     * journee le depasse de toute facon.
     */
    public function billableDays(Carbon $startsAt, Carbon $endsAt): float
    {
        $minutes = $startsAt->diffInMinutes($endsAt);

        return max(1.0, (float) ceil($minutes / (60 * self::HEURES_PAR_JOUR)));
    }

    /**
     * La duree demandee tient-elle dans les bornes du mode ?
     *
     * La regle vit ici, avec le calcul des unites facturees : c'est la meme
     * duree qui est comptee et qui est bornee. Sans ce controle, une course
     * de 24 heures reservee a l'heure se facture 24 heures la ou la journee
     * coute bien moins — c'est le prix qui viendrait du client.
     */
    public function dureeDansLesBornes(string $mode, Carbon $startsAt, Carbon $endsAt): bool
    {
        [$min, $max] = $this->bornes($mode);

        $unites = $mode === self::MODE_DAILY
            ? $this->billableDays($startsAt, $endsAt)
            : $this->billableHours($startsAt, $endsAt);

        return $unites >= $min && $unites <= $max;
    }

    /**
     * Les bornes du mode, en unites facturees.
     *
     * @return array{0: int, 1: int} le minimum et le maximum
     */
    public function bornes(string $mode): array
    {
        return $mode === self::MODE_DAILY
            ? [$this->settings->days_min, $this->settings->days_max]
            : [$this->settings->hours_min, $this->settings->hours_max];
    }

    /** La phrase montree au client quand sa duree sort des bornes. */
    public function messageHorsBornes(string $mode): string
    {
        [$min, $max] = $this->bornes($mode);

        $unite = $mode === self::MODE_DAILY ? 'jours' : 'heures';

        return "La durée doit être comprise entre {$min} et {$max} {$unite}.";
    }

    public function refundFor(float $amountPaid): float
    {
        return round($amountPaid * $this->settings->refund_percentage / 100, 2);
    }
}
