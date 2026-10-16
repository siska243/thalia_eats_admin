<?php

namespace App\Http\Controllers\Api\Rental;

use App\Exceptions\BookingRefusee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rental\BookingRequest;
use App\Http\Requests\Rental\DepositRequest;
use App\Http\Resources\Rental\BookingResource;
use App\Models\Booking;
use App\Models\Vehicle;
use App\Services\Rental\BookingCreator;
use App\Services\Rental\BookingPricing;
use App\Services\Rental\BookingWorkflow;
use App\Services\Rental\DepositPayment;
use App\Settings\RentalSettings;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use App\Wrappers\LibPhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Les reservations du client connecte. */
class BookingController extends Controller
{
    public function __construct(
        private readonly BookingCreator $creator,
        private readonly BookingWorkflow $workflow,
        private readonly DepositPayment $deposit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::query()
            ->where('user_id', $request->user()->id)
            ->with(['vehicle.currency', 'chauffeur', 'currency'])
            ->latest('starts_at')
            ->get();

        return ApiResponse::GET_DATA([
            'data' => BookingResource::collection($bookings),
        ]);
    }

    public function show(Request $request, string $uid): JsonResponse
    {
        $booking = $this->trouver($request, $uid);

        if (! $booking) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette réservation est introuvable');
        }

        return ApiResponse::GET_DATA([
            'data' => new BookingResource($booking),
        ]);
    }

    public function store(BookingRequest $request): JsonResponse
    {
        $vehicle = $this->vehiculeDepuisUid($request->input('vehicle'));

        if (! $vehicle) {
            return ApiResponse::NOT_FOUND('Oups', 'Ce véhicule est introuvable');
        }

        try {
            $booking = $this->creator->create(
                $request->user(),
                $vehicle,
                Carbon::parse($request->input('starts_at')),
                Carbon::parse($request->input('ends_at')),
                $request->only([
                    'pickup_location', 'pickup_lat', 'pickup_lng', 'pickup_town_id',
                    'dropoff_location', 'dropoff_lat', 'dropoff_lng',
                    // Le type de course, son commentaire et l'option parking.
                    // Les trois sont facultatifs : une application installee
                    // n'en envoie aucun, et sa reservation passe comme avant.
                    'course_type_id', 'course_note', 'parking',
                ]),
                $request->input('pricing_mode', BookingPricing::MODE_HOURLY),
            );
        } catch (BookingRefusee $e) {
            return ApiResponse::BAD_REQUEST(
                $e->raison,
                'Oups',
                // Le message peut dependre des reglages (les bornes de
                // duree) : le service le compose, le controleur le relaie.
                $e->messageClient ?? $this->messageDeRefus($e->raison),
            );
        }

        return ApiResponse::SUCCESS_DATA(
            new BookingResource($booking->load(['vehicle.currency', 'chauffeur', 'currency'])),
            'Réservation enregistrée',
            "Réglez l'acompte pour confirmer : le véhicule reste disponible tant qu'il n'est pas payé.",
        );
    }

    /**
     * Le client engage le paiement de son acompte.
     *
     * Rien n'est confirme au retour de cet appel : la passerelle debite le
     * client sur son combine, et c'est le webhook qui tranchera, sous verrou,
     * si le creneau est encore libre. L'application doit donc relire la
     * reservation — son `status` passe a `confirmed` ou a `lost`.
     */
    public function deposit(DepositRequest $request, string $uid): JsonResponse
    {
        $booking = $this->trouver($request, $uid);

        if (! $booking) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette réservation est introuvable');
        }

        $method = (string) $request->input('method', 'mobile');
        $phone = $method === 'mobile' ? (string) $request->input('phone') : '';

        if ($method === 'mobile' && ! $this->numeroValide($phone)) {
            return ApiResponse::BAD_REQUEST('telephone_invalide', 'Oups', 'Numéro de téléphone invalide.');
        }

        $resultat = $this->deposit->initier($booking, $method, $phone, $request->only([
            'success_url', 'error_url', 'cancel_url',
        ]));

        if (! $resultat['ok']) {
            return ApiResponse::BAD_REQUEST(
                $resultat['raison'],
                'Oups',
                $resultat['message'] ?? $this->messageDeRefus($resultat['raison']),
            );
        }

        return ApiResponse::GET_DATA([
            'data' => [
                'order_number' => $resultat['orderNumber'],
                // Non nulle en carte seulement : c'est la page de saisie a
                // ouvrir. En mobile money, le client valide sur son telephone.
                'url' => $resultat['url'],
            ],
        ]);
    }

    /**
     * Le client annule sa reservation.
     *
     * Possible tant que la course n'a pas commence. Le remboursement du est
     * calcule sur ce qui a reellement ete paye ; le virement reste manuel.
     */
    public function cancel(Request $request, string $uid): JsonResponse
    {
        $booking = $this->trouver($request, $uid);

        if (! $booking) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette réservation est introuvable');
        }

        if (! $booking->isCancellable()) {
            return ApiResponse::BAD_REQUEST(
                'annulation_impossible',
                'Oups',
                'Cette réservation ne peut plus être annulée.',
            );
        }

        $this->workflow->cancel($booking, $request->input('reason'));

        return ApiResponse::SUCCESS_DATA(
            new BookingResource($booking->refresh()),
            'Réservation annulée',
            $booking->refund_amount > 0
                ? 'Le remboursement sera traité par notre équipe.'
                : 'Aucun paiement n\'avait été enregistré.',
        );
    }

    /**
     * Retrouve une reservation appartenant au demandeur.
     *
     * L'uid n'est pas une capacite : Cipher chiffre un entier avec une cle et
     * un IV ecrits dans le depot, sans MAC. N'importe qui peut donc forger
     * l'uid d'une autre reservation. L'autorisation vient de la clause SQL,
     * pas du fait de connaitre l'identifiant.
     */
    private function trouver(Request $request, string $uid): ?Booking
    {
        $id = Cipher::Decrypt($uid);

        if (! $id) {
            return null;
        }

        return Booking::query()
            ->whereKey($id)
            ->where('user_id', $request->user()->id)
            ->with(['vehicle.currency', 'chauffeur', 'currency'])
            ->first();
    }

    private function vehiculeDepuisUid(string $uid): ?Vehicle
    {
        $id = Cipher::Decrypt($uid);

        return $id ? Vehicle::query()->whereKey($id)->first() : null;
    }

    /**
     * LibPhoneNumber::numberProto() renvoie l'exception de parsing au lieu de
     * la lever : sans ce garde, un numero fantaisiste fait tomber la route en
     * 500 au lieu de rendre un 400 lisible.
     */
    private function numeroValide(string $phone): bool
    {
        try {
            return (new LibPhoneNumber($phone))->checkValidationNumber();
        } catch (\Throwable) {
            return false;
        }
    }

    private function messageDeRefus(string $raison): string
    {
        return match ($raison) {
            BookingRefusee::VEHICULE_INTROUVABLE => "Ce véhicule n'est plus proposé à la location.",
            BookingRefusee::VEHICULE_INDISPONIBLE => 'Ce véhicule est déjà réservé sur ce créneau.',
            // Pas le même message que « déjà réservé » : ce créneau-là peut
            // revenir. Le client doit savoir qu'il a une seconde chance,
            // sinon il abandonne sur un refus qu'il croit définitif.
            BookingRefusee::PAIEMENT_EN_COURS => 'Ce véhicule est en cours de réservation par un autre client. Réessayez dans quelques minutes, ou choisissez un autre véhicule.',
            BookingRefusee::CRENEAU_INVALIDE => 'La fin doit suivre le début.',
            BookingRefusee::MODE_INDISPONIBLE => "Ce véhicule n'est pas proposé à la journée.",
            BookingRefusee::DUREE_HORS_BORNES => "La durée demandée n'est pas autorisée.",
            // Filet seulement : le service compose la phrase, qui nomme le
            // delai lu dans les reglages.
            BookingRefusee::DELAI_PREVENANCE => 'Le départ demandé est trop proche.',
            /*
             * Ces trois-la portent normalement leur propre message, compose
             * par le service avec le nom de la ville ou du type. Ce repli ne
             * sert que si un appelant leve le refus sans message.
             */
            BookingRefusee::HORS_VILLE => "Ce véhicule n'effectue pas de course au départ de cette ville.",
            BookingRefusee::HORS_COMMUNE => "Ce véhicule n'effectue pas de course au départ de cette commune.",
            BookingRefusee::MODE_IMPOSE => 'Ce type de course impose un mode de réservation.',
            BookingRefusee::DUREE_MINIMALE_TYPE => 'Ce type de course demande une durée minimale.',
            // Le service compose le message : il nomme la réservation qui
            // bloque. Cette phrase n'est qu'un filet si la référence manque.
            BookingRefusee::RESERVATION_NON_REGLEE => "Vous avez déjà une réservation non réglée. Payez-la ou annulez-la avant d'en réserver une autre.",
            // Le texte vient des reglages : c'est la meme phrase que celle
            // affichee a cote du bouton WhatsApp, et le commercial la change
            // sans livraison.
            BookingRefusee::HORS_ZONE => app(RentalSettings::class)->out_of_zone_message,
            'acompte_non_attendu' => "Cette réservation n'attend plus d'acompte.",
            'reservation_expiree' => 'Cette réservation a expiré faute de paiement. Réservez à nouveau.',
            'creneau_passe' => 'Ce créneau est déjà passé.',
            'paiement_refuse' => "Le paiement n'a pas pu être engagé, réessayez.",
            default => "Cette réservation n'a pas pu être enregistrée.",
        };
    }
}
