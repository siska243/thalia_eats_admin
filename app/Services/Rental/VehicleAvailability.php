<?php

namespace App\Services\Rental;

use App\Models\Booking;
use App\Models\Chauffeur;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Qui est libre, et quand.
 *
 * Seule implementation de la question « ce vehicule est-il disponible entre
 * telle et telle heure ». Elle est posee au catalogue, a la creation d'une
 * reservation, a la reception d'un paiement et dans l'administration : quatre
 * copies auraient diverge a la premiere correction.
 *
 * Une reservation non payee ne retient rien. Le premier qui paie emporte le
 * vehicule : seuls les statuts `confirmed` et `in_progress` occupent le
 * creneau.
 */
class VehicleAvailability
{
    public function isVehicleAvailable(
        Vehicle $vehicle,
        Carbon $startsAt,
        Carbon $endsAt,
        ?Booking $ignoring = null,
    ): bool {
        return ! $this->conflictsFor($vehicle, $startsAt, $endsAt, $ignoring)->exists();
    }

    /**
     * Les reservations qui empechent celle-ci.
     *
     * `$ignoring` sert a la modification : une reservation ne se chevauche pas
     * elle-meme. Sans ce parametre, editer l'heure d'une reservation existante
     * depuis l'administration serait toujours refuse.
     */
    public function conflictsFor(
        Vehicle $vehicle,
        Carbon $startsAt,
        Carbon $endsAt,
        ?Booking $ignoring = null,
    ) {
        return Booking::query()
            ->where('vehicle_id', $vehicle->id)
            ->holding()
            ->overlapping($startsAt, $endsAt)
            ->when($ignoring?->exists, fn ($query) => $query->whereKeyNot($ignoring->getKey()));
    }

    /**
     * Le chauffeur est-il libre sur ce creneau ?
     *
     * Un chauffeur ne se dedouble pas : l'affecter a deux courses qui se
     * chevauchent produit une course sans conducteur le jour venu.
     */
    public function isChauffeurAvailable(
        Chauffeur $chauffeur,
        Carbon $startsAt,
        Carbon $endsAt,
        ?Booking $ignoring = null,
    ): bool {
        return ! Booking::query()
            ->where('chauffeur_id', $chauffeur->id)
            ->holding()
            ->overlapping($startsAt, $endsAt)
            ->when($ignoring?->exists, fn ($query) => $query->whereKeyNot($ignoring->getKey()))
            ->exists();
    }

    /** Les vehicules proposables pour un creneau donne. */
    public function availableVehicles(Carbon $startsAt, Carbon $endsAt): Collection
    {
        return Vehicle::query()
            ->rentable()
            ->whereDoesntHave('bookings', fn ($query) => $query
                ->holding()
                ->overlapping($startsAt, $endsAt))
            ->orderBy('hourly_rate')
            ->get();
    }

    /** Les chauffeurs affectables sur un creneau. */
    public function availableChauffeurs(Carbon $startsAt, Carbon $endsAt, ?Booking $ignoring = null): Collection
    {
        return Chauffeur::query()
            ->assignable()
            ->whereDoesntHave('bookings', fn ($query) => $query
                ->holding()
                ->overlapping($startsAt, $endsAt)
                ->when($ignoring?->exists, fn ($inner) => $inner->whereKeyNot($ignoring->getKey())))
            ->orderBy('full_name')
            ->get();
    }
}
