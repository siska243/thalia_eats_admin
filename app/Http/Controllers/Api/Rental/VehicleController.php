<?php

namespace App\Http\Controllers\Api\Rental;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rental\VehicleSearchRequest;
use App\Http\Resources\Rental\VehicleResource;
use App\Models\Booking;
use App\Models\CourseType;
use App\Models\Vehicle;
use App\Services\Rental\BookingPricing;
use App\Services\Rental\VehicleAvailability;
use App\Wrappers\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
    /** Quinze par page : trois ecrans de cartes sur un telephone. */
    private const PAR_PAGE = 15;

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

        /*
         * Une requete, jamais une collection : le catalogue se pagine, et
         * filtrer une collection deja chargee obligerait a lire tout le parc
         * pour en renvoyer quinze.
         */
        $requete = $startsAt && $endsAt
            ? $this->availability->availableVehiclesQuery($startsAt, $endsAt)
            : Vehicle::query()->rentable()->orderBy('hourly_rate');

        $this->appliquerRecherche($requete, $search);
        $this->appliquerFiltres($requete, $request);

        $page = $requete->with(['currency', 'city', 'coursePrices.courseType'])->paginate(self::PAR_PAGE);

        // Un seul appel pour toute la page : demander vehicule par vehicule
        // s'il est libre ferait une requete par ligne affichee.
        $enCourse = $startsAt ? collect() : $this->vehiculesEnCourse();

        $data = collect($page->items())->map(
            fn (Vehicle $vehicle) => $this->ligne($vehicle, $request, $startsAt, $endsAt, $mode, $enCourse)
        );

        return ApiResponse::GET_DATA([
            'data' => $data->values(),
            /*
             * De quoi savoir s'il reste quelque chose a charger. Sans cette
             * information, le defilement infini redemande indefiniment une
             * page qui n'existe pas.
             */
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /**
     * Les filtres du catalogue, poses sur la REQUETE.
     *
     * Sur la requete et jamais sur la page : filtrer une page deja chargee
     * donnerait des pages a trous, et filtrer une collection complete
     * obligerait a lire tout le parc pour en renvoyer quinze.
     *
     * Chacun ne s'applique que s'il est transmis : une application deja
     * installee n'en envoie aucun, et son catalogue ne change pas.
     *
     * @param  Builder<Vehicle>  $requete
     */
    private function appliquerFiltres(Builder $requete, VehicleSearchRequest $request): void
    {
        if ($ville = $request->integer('city_id')) {
            $requete->where('city_id', $ville);
        }

        /*
         * Un vehicule SANS commune sort toujours : il dessert toute sa ville,
         * et le retirer cacherait justement celui qui accepte la course.
         */
        if ($communes = array_filter((array) $request->input('town_ids', []))) {
            $requete->where(fn (Builder $q) => $q
                ->whereDoesntHave('towns')
                ->orWhereHas('towns', fn (Builder $t) => $t->whereIn('towns.id', $communes)));
        }

        if ($type = $request->integer('course_type_id')) {
            $requete->whereHas('coursePrices', fn (Builder $q) => $q->where('course_type_id', $type));
        }

        /*
         * `proposes` et NON `pricing_mode` : ce sont deux questions
         * differentes, et les confondre casse un comportement voulu.
         *
         * `pricing_mode` dit comment CHIFFRER, et un vehicule sans tarif
         * journalier reste chiffre a l'heure quand la journee est demandee —
         * le cacher du catalogue laisserait croire qu'il n'existe pas. C'est
         * ecrit dans `ligne()` et c'etait deja teste.
         *
         * `proposes` dit ce qu'on CHERCHE, et la le client demande a ne voir
         * que ce qu'il peut reserver ainsi. A la journee, la case et le tarif
         * vont ensemble : cocher sans prix ne propose rien.
         */
        $propose = $request->input('proposes');

        if ($propose === BookingPricing::MODE_DAILY) {
            $requete->whereNotNull('daily_rate')->where('daily_rate', '>', 0);
        }

        if ($propose === BookingPricing::MODE_HOURLY) {
            $requete->whereNotNull('hourly_rate')->where('hourly_rate', '>', 0);
        }
    }

    /**
     * Une ligne du catalogue : la ressource, plus son prix et sa disponibilite.
     *
     * Ecrite une fois pour la liste et la fiche, qui doivent annoncer
     * exactement la meme chose : ecrite deux fois, elle finirait par diverger.
     *
     * @param  Collection<int, int>  $enCourse
     * @return array<string, mixed>
     */
    private function ligne(
        Vehicle $vehicle,
        VehicleSearchRequest $request,
        ?Carbon $startsAt,
        ?Carbon $endsAt,
        string $mode,
        Collection $enCourse,
    ): array {
        $ligne = (new VehicleResource($vehicle))->toArray($request);

        if (! $startsAt || ! $endsAt) {
            return array_merge($ligne, [
                'available' => ! $enCourse->contains($vehicle->id),
                // Sans creneau, il n'y a pas de total a annoncer : seul le
                // tarif horaire a un sens, et il est deja dans la ressource.
                'quote' => null,
            ]);
        }

        /*
         * Un vehicule sans tarif journalier reste chiffre a l'heure, meme si
         * le mode journee est demande : le cacher du catalogue laisserait
         * croire qu'il n'existe pas.
         */
        $modeApplique = $mode === BookingPricing::MODE_DAILY
            && ! $this->pricing->estProposeALaJournee($vehicle)
                ? BookingPricing::MODE_HOURLY
                : $mode;

        /*
         * Le type demande chiffre la course, s'il en existe un. Le catalogue
         * doit annoncer exactement ce que la reservation facturera.
         */
        $courseType = $request->filled('course_type_id')
            ? CourseType::query()->find($request->integer('course_type_id'))
            : null;

        $quote = $this->pricing->quote(
            $vehicle,
            $startsAt,
            $endsAt,
            $modeApplique,
            $courseType,
            $request->boolean('parking'),
        );

        return array_merge($ligne, [
            // Dans la liste, ils sortent tous de availableVehiclesQuery : ils
            // sont libres sur ce creneau, par construction. La fiche, elle,
            // corrige ce drapeau pour le vehicule qu'elle affiche.
            'available' => true,
            'quote' => [
                'mode' => $quote->mode,
                'unit_price' => $quote->unitPrice,
                'billed_units' => $quote->billedUnits,
                'duration_hours' => $quote->durationHours,
                // Le detail, pour que l'ecran puisse dire d'ou vient le total
                // au lieu de laisser croire a un prix change.
                'base_price' => $quote->base,
                'parking_fee' => $quote->parkingFee,
                'total' => $quote->total,
                'deposit' => $quote->deposit,
                'balance' => $quote->balance,
                'currency' => $quote->currency,
            ],
        ]);
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
            ->with(['currency', 'city', 'coursePrices.courseType'])
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

        /*
         * La fiche passe par la meme construction que la liste : les deux
         * doivent annoncer exactement la meme chose, et c'est en les ecrivant
         * deux fois qu'elles finissent par diverger.
         */
        $ligne = $this->ligne(
            $vehicle,
            $request,
            $startsAt,
            $endsAt,
            $request->input('pricing_mode', BookingPricing::MODE_HOURLY),
            $this->vehiculesEnCourse(),
        );

        /*
         * La nuance propre a la fiche : avec un creneau, elle dit si CE
         * vehicule est libre sur ces heures. La liste, elle, ne montre que
         * des vehicules libres par construction.
         */
        if ($startsAt && $endsAt) {
            $ligne['available'] = $this->availability->isVehicleAvailable($vehicle, $startsAt, $endsAt);
        }

        return ApiResponse::GET_DATA(['data' => $ligne]);
    }

    /**
     * Recherche par marque, modele et description, comme /list-restaurant sur
     * le nom.
     *
     * En SQL et pas sur la collection : le catalogue est pagine, filtrer
     * apres coup ne renverrait que les resultats de la page courante.
     */
    private function appliquerRecherche(Builder $requete, ?string $search): void
    {
        if (! $search) {
            return;
        }

        $terme = '%'.trim($search).'%';

        $requete->where(fn (Builder $q) => $q
            ->where('brand', 'like', $terme)
            ->orWhere('model', 'like', $terme)
            ->orWhere('description', 'like', $terme));
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
