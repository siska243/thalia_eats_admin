<?php

namespace App\Http\Controllers\Api\Rental;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rental\VehicleSearchRequest;
use App\Http\Resources\Rental\VehicleResource;
use App\Services\Rental\BookingPricing;
use App\Services\Rental\VehicleAvailability;
use App\Wrappers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/** Le catalogue des vehicules de location. */
class VehicleController extends Controller
{
    public function __construct(
        private readonly VehicleAvailability $availability,
        private readonly BookingPricing $pricing,
    ) {}

    /**
     * Les vehicules libres sur un creneau, avec leur prix pour ce creneau.
     *
     * Le prix accompagne chaque vehicule : sans lui, le client compare des
     * tarifs horaires et decouvre le total une fois son choix fait. Il est
     * calcule par le meme service que la reservation, donc le montant annonce
     * est celui qui sera facture.
     */
    public function index(VehicleSearchRequest $request): JsonResponse
    {
        $startsAt = Carbon::parse($request->input('starts_at'));
        $endsAt = Carbon::parse($request->input('ends_at'));

        $data = $this->availability
            ->availableVehicles($startsAt, $endsAt)
            ->map(function ($vehicle) use ($request, $startsAt, $endsAt) {
                $quote = $this->pricing->quote($vehicle, $startsAt, $endsAt);

                return array_merge(
                    (new VehicleResource($vehicle))->toArray($request),
                    ['quote' => [
                        'duration_hours' => $quote->durationHours,
                        'total' => $quote->total,
                        'deposit' => $quote->deposit,
                        'balance' => $quote->balance,
                        'currency' => $quote->currency,
                    ]],
                );
            });

        return ApiResponse::GET_DATA(['data' => $data]);
    }
}
