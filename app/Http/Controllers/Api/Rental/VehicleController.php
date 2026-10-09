<?php

namespace App\Http\Controllers\Api\Rental;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rental\VehicleSearchRequest;
use App\Http\Resources\Rental\VehicleResource;
use App\Models\Booking;
use App\Models\Vehicle;
use App\Services\Rental\BookingPricing;
use App\Services\Rental\VehicleAvailability;
use App\Wrappers\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Le catalogue des vehicules de location.
 *
 * Il se consulte de deux facons, comme la liste des restaurants :
 *
 * - SANS creneau, c'est une vitrine. Tous les vehicules proposes a la
 *   location, avec leur tarif horaire, et un drapeau `available` qui dit
 *   lesquels sont en course a cet instant. Les indisponibles sont rendus
 *   quand meme, pour etre grises plutot que caches : un vehicule pris ce
 *   matin sera peut-etre libre demain, et le retirer du catalogue laisserait
 *   croire qu'il n'existe pas.
 *
 * - AVEC un creneau, c'est une recherche. Seuls les vehicules libres sur ces
 *   heures, chacun avec le prix de ce creneau, calcule par le meme service
 *   que la reservation : le montant annonce est celui qui sera facture.
 */
class VehicleController extends Controller
{
    public function __construct(
        private readonly VehicleAvailability $availability,
        private readonly BookingPricing $pricing,
    ) {}

    public function index(VehicleSearchRequest $request): JsonResponse
    {
        $search = $request->input('search');
        $mode = $request->input('pricing_mode', BookingPricing::MODE_HOURLY);

        $startsAt = $request->filled('starts_at')
            ? Carbon::parse($request->input('starts_at'))
            : null;

        $endsAt = $request->filled('ends_at')
            ? Carbon::parse($request->input('ends_at'))
            : null;

        $vehicles = $startsAt && $endsAt
            ? $this->availability->availableVehicles($startsAt, $endsAt)
            : Vehicle::query()->rentable()->with('currency')->get();

        $vehicles = $this->filtrer($vehicles, $search);

        // Un seul appel pour tout le catalogue : demander vehicule par
        // vehicule s'il est libre ferait une requete par ligne affichee.
        $enCourse = $startsAt ? collect() : $this->vehiculesEnCourse();

        $data = $vehicles->map(function (Vehicle $vehicle) use ($request, $startsAt, $endsAt, $enCourse, $mode) {
            $ligne = (new VehicleResource($vehicle))->toArray($request);

            if ($startsAt && $endsAt) {
                /*
                 * Un vehicule sans tarif journalier reste chiffre a l'heure,
                 * meme si le mode journee est demande : le cacher du catalogue
                 * laisserait croire qu'il n'existe pas.
                 */
                $modeApplique = $mode === BookingPricing::MODE_DAILY
                    && ! $this->pricing->estProposeALaJournee($vehicle)
                        ? BookingPricing::MODE_HOURLY
                        : $mode;

                $quote = $this->pricing->quote($vehicle, $startsAt, $endsAt, $modeApplique);

                return array_merge($ligne, [
                    // Ils sortent tous de availableVehicles : ils sont libres
                    // sur ce creneau, par construction.
                    'available' => true,
                    'quote' => [
                        'mode' => $quote->mode,
                        'unit_price' => $quote->unitPrice,
                        'billed_units' => $quote->billedUnits,
                        'duration_hours' => $quote->durationHours,
                        'total' => $quote->total,
                        'deposit' => $quote->deposit,
                        'balance' => $quote->balance,
                        'currency' => $quote->currency,
                    ],
                ]);
            }

            return array_merge($ligne, [
                'available' => ! $enCourse->contains($vehicle->id),
                // Sans creneau, il n'y a pas de total a annoncer : seul le
                // tarif horaire a un sens, et il est deja dans la ressource.
                'quote' => null,
            ]);
        });

        return ApiResponse::GET_DATA(['data' => $data->values()]);
    }

    /**
     * Une fiche vehicule, comme /list-restaurant/{slug}.
     *
     * Le creneau reste facultatif : on ouvre la fiche pour regarder la
     * voiture, et le prix n'apparait qu'une fois les heures choisies. Fourni,
     * il rend le devis ET dit si le vehicule est libre sur ces heures precises
     * — ce que le drapeau `available` du catalogue, calcule sur l'instant, ne
     * peut pas dire.
     */
    public function show(VehicleSearchRequest $request, string $slug): JsonResponse
    {
        $vehicle = Vehicle::query()
            ->rentable()
            ->with('currency')
            ->where('slug', $slug)
            ->first();

        if (! $vehicle) {
            return ApiResponse::NOT_FOUND('Oups', 'Ce véhicule est introuvable');
        }

        $startsAt = $request->filled('starts_at')
            ? Carbon::parse($request->input('starts_at'))
            : null;

        $endsAt = $request->filled('ends_at')
            ? Carbon::parse($request->input('ends_at'))
            : null;

        $ligne = (new VehicleResource($vehicle))->toArray($request);

        if (! $startsAt || ! $endsAt) {
            return ApiResponse::GET_DATA(['data' => array_merge($ligne, [
                'available' => ! $this->vehiculesEnCourse()->contains($vehicle->id),
                'quote' => null,
            ])]);
        }

        $libre = $this->availability->isVehicleAvailable($vehicle, $startsAt, $endsAt);

        $mode = $request->input('pricing_mode', BookingPricing::MODE_HOURLY);

        if ($mode === BookingPricing::MODE_DAILY && ! $this->pricing->estProposeALaJournee($vehicle)) {
            $mode = BookingPricing::MODE_HOURLY;
        }

        $quote = $this->pricing->quote($vehicle, $startsAt, $endsAt, $mode);

        return ApiResponse::GET_DATA(['data' => array_merge($ligne, [
            'available' => $libre,
            'quote' => [
                'mode' => $quote->mode,
                'unit_price' => $quote->unitPrice,
                'billed_units' => $quote->billedUnits,
                'duration_hours' => $quote->durationHours,
                'total' => $quote->total,
                'deposit' => $quote->deposit,
                'balance' => $quote->balance,
                'currency' => $quote->currency,
            ],
        ])]);
    }

    /**
     * Recherche par marque et modele, comme /list-restaurant sur le nom.
     *
     * Elle porte sur la liste deja chargee plutot que sur une clause SQL : le
     * chemin avec creneau passe par `availableVehicles`, qui rend une
     * collection, pas une requete. Filtrer aux deux endroits differemment
     * aurait donne deux comportements de recherche selon qu'un creneau est
     * fourni ou non.
     */
    private function filtrer(Collection $vehicles, ?string $search): Collection
    {
        if (! $search) {
            return $vehicles;
        }

        $terme = mb_strtolower(trim($search));

        return $vehicles->filter(
            fn (Vehicle $vehicle) => str_contains(
                mb_strtolower("{$vehicle->brand} {$vehicle->model} {$vehicle->description}"),
                $terme,
            )
        );
    }

    /** Les vehicules occupes a cet instant precis. */
    private function vehiculesEnCourse()
    {
        return Booking::query()
            ->holding()
            ->overlapping(now(), now())
            ->pluck('vehicle_id')
            ->unique();
    }
}
