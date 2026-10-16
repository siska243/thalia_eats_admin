<?php

namespace App\Services\Rental;

use App\Models\CourseType;
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

    /**
     * Le chiffrage d'une course.
     *
     * Le type de course est FACULTATIF : les applications installees n'en
     * envoient pas, et leurs reservations doivent continuer de passer au tarif
     * d'avant. Fourni, il apporte deux choses et rien d'autre :
     *
     * - le tarif propre du vehicule pour ce type, s'il en a un. Sinon le
     *   vehicule garde son prix habituel : « tu mets le prix par defaut comme
     *   il est maintenant et ensuite tu ajoutes le prix en fonction du type » ;
     * - l'option parking, quand le TYPE la propose et que le client la coche.
     *
     * Les regles vivent sur le referentiel, jamais ici : aucun test sur le
     * code du type dans ce fichier, sans quoi ajouter un quatrieme type
     * demanderait une livraison.
     */
    public function quote(
        Vehicle $vehicle,
        Carbon $startsAt,
        Carbon $endsAt,
        string $mode = self::MODE_HOURLY,
        ?CourseType $courseType = null,
        bool $parking = false,
        ?float $tarifFige = null,
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

        /*
         * Le tarif FIGE l'emporte sur tout.
         *
         * Une reservation deja prise garde le prix convenu : changer le tarif
         * du vehicule ne doit pas reprendre une course en cours, ni une course
         * dont le client a deja paye l'acompte. L'appelant le fournit quand il
         * rechiffre une reservation existante sans en changer l'offre.
         *
         * Sinon : le tarif du type s'il existe, celui du vehicule par defaut.
         * La lecture vit sur le modele, une seule fois.
         */
        $unitPrice = $tarifFige ?? $vehicle->tarifPour($courseType, $mode)
            ?? ($mode === self::MODE_DAILY
                ? (float) $vehicle->daily_rate
                : (float) $vehicle->hourly_rate);

        $units = $mode === self::MODE_DAILY
            ? $this->billableDays($startsAt, $endsAt)
            : $this->billableHours($startsAt, $endsAt);

        $base = round($unitPrice * $units, 2);

        /*
         * Le parking aeroport, facture sur les HEURES de la course et non sur
         * les unites : « 10 $ de plus par heure pour le parking ». A la
         * journee, les unites sont des jours et le compte serait vingt-quatre
         * fois trop bas.
         *
         * C'est le referentiel qui decide si l'option existe : un type qui ne
         * la propose pas ne facture rien, meme si l'appelant la demande.
         */
        $parkingFee = $parking
            ? round($vehicle->tarifParkingPour($courseType) * $this->billableHours($startsAt, $endsAt), 2)
            : 0.0;

        $total = round($base + $parkingFee, 2);
        $deposit = round($total * $this->pourcentageAcompte($vehicle) / 100, 2);

        return new BookingQuote(
            mode: $mode,
            unitPrice: $unitPrice,
            billedUnits: $units,
            hourlyRate: (float) $vehicle->hourly_rate,
            // Les heures facturables, quel que soit le mode : c'est ce que la
            // colonne signifie depuis le depart et ce que les ecrans
            // affichent. Les unites reellement facturees sont a part.
            durationHours: $this->billableHours($startsAt, $endsAt),
            base: $base,
            parkingFee: $parkingFee,
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
     * La journee est-elle proposee ?
     *
     * Elle se COCHE en administration, et ne se deduit plus de la presence
     * d'un tarif : un vehicule qu'on ne veut plus louer a la journee n'avait
     * aucun moyen de le dire, sinon effacer son tarif — qui sert encore a
     * l'affichage.
     *
     * La case ET le tarif : cocher sans prix ne propose rien, parce que le
     * client ne pourrait pas savoir ce qu'il paie.
     */
    public function estProposeALaJournee(Vehicle $vehicle): bool
    {
        return (bool) $vehicle->allows_daily
            && $vehicle->daily_rate !== null
            && (float) $vehicle->daily_rate > 0;
    }

    /** Jumelle de la precedente, pour l'heure. */
    public function estProposeALHeure(Vehicle $vehicle): bool
    {
        return (bool) $vehicle->allows_hourly && (float) $vehicle->hourly_rate > 0;
    }

    /**
     * L'acompte applique : celui du vehicule, celui des reglages sinon.
     *
     * **La lecture vit ici, une seule fois.** Le devis et l'affichage posent
     * la meme question, et deux lectures finiraient par diverger.
     */
    public function pourcentageAcompte(Vehicle $vehicle): float
    {
        return $vehicle->deposit_percentage !== null
            ? (float) $vehicle->deposit_percentage
            : (float) $this->settings->deposit_percentage;
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

    /**
     * Le depart respecte-t-il le delai de prevenance ?
     *
     * Ni dans le passe, ni dans l'instant : une course demande qu'on joigne
     * un chauffeur et qu'il roule. La regle est rangee avec les bornes de
     * duree parce que c'est la meme matiere — ce qu'un creneau a le droit
     * d'etre — et qu'une seule classe doit pouvoir repondre pour toutes.
     */
    public function respecteLeDelaiDePrevenance(Carbon $startsAt): bool
    {
        return $startsAt->greaterThanOrEqualTo(
            now()->addMinutes($this->settings->lead_time_minutes),
        );
    }

    /** La phrase montree au client quand son depart est trop proche. */
    public function messageDelaiDePrevenance(): string
    {
        $minutes = $this->settings->lead_time_minutes;

        return "Le départ doit être prévu au moins {$minutes} minutes à l'avance.";
    }

    public function refundFor(float $amountPaid): float
    {
        return round($amountPaid * $this->settings->refund_percentage / 100, 2);
    }
}
