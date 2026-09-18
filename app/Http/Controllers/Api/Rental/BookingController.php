<?php

namespace App\Http\Controllers\Api\Rental;

use App\Exceptions\BookingRefusee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rental\BookingRequest;
use App\Http\Resources\Rental\BookingResource;
use App\Models\Booking;
use App\Models\Vehicle;
use App\Services\Rental\BookingCreator;
use App\Services\Rental\BookingWorkflow;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Les reservations du client connecte. */
class BookingController extends Controller
{
    public function __construct(
        private readonly BookingCreator $creator,
        private readonly BookingWorkflow $workflow,
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
                    'pickup_location', 'pickup_lat', 'pickup_lng',
                    'dropoff_location', 'dropoff_lat', 'dropoff_lng',
                ]),
            );
        } catch (BookingRefusee $e) {
            return ApiResponse::BAD_REQUEST(
                $e->raison,
                'Oups',
                $this->messageDeRefus($e->raison),
            );
        }

        return ApiResponse::SUCCESS_DATA(
            new BookingResource($booking->load(['vehicle.currency', 'chauffeur', 'currency'])),
            'Réservation enregistrée',
            "Réglez l'acompte pour confirmer : le véhicule reste disponible tant qu'il n'est pas payé.",
        );
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

    private function messageDeRefus(string $raison): string
    {
        return match ($raison) {
            BookingRefusee::VEHICULE_INTROUVABLE => "Ce véhicule n'est plus proposé à la location.",
            BookingRefusee::VEHICULE_INDISPONIBLE => 'Ce véhicule est déjà réservé sur ce créneau.',
            BookingRefusee::CRENEAU_INVALIDE => 'La fin doit suivre le début.',
            default => "Cette réservation n'a pas pu être enregistrée.",
        };
    }
}
