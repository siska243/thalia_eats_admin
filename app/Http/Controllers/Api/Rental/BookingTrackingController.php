<?php

namespace App\Http\Controllers\Api\Rental;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Suivi\SuiviPositions;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le suivi d'une course de location : le chauffeur emet, le client regarde.
 *
 * Le chauffeur de location est informatif : il n'accepte ni ne refuse une
 * course, son ecran n'envoie que sa position.
 *
 * L'uid n'est pas une capacite — `Cipher` chiffre un entier sans MAC,
 * n'importe qui peut en forger un. Les deux routes tirent donc leur
 * autorisation d'une clause SQL, decalquee du controle deja ecrit pour les
 * livreurs de repas, qui existe precisement parce que sans lui n'importe quel
 * compte pouvait faire croire a un client que sa course approchait.
 */
class BookingTrackingController extends Controller
{
    public function __construct(private readonly SuiviPositions $suivi) {}

    /** Le chauffeur affecte remonte sa position. */
    public function position(Request $request, string $uid): JsonResponse
    {
        $donnees = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $id = Cipher::Decrypt($uid);

        $booking = $id
            ? Booking::query()
                ->whereKey($id)
                ->whereHas('chauffeur', fn ($q) => $q->where('user_id', $request->user()->id))
                ->first()
            : null;

        if (! $booking) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette course est introuvable');
        }

        if (! $this->suivi->fenetreActive($booking)) {
            return ApiResponse::BAD_REQUEST(
                'hors_course',
                'Oups',
                "Cette course n'est pas en cours : aucune position n'est attendue.",
            );
        }

        /*
         * Un point trop rapproche du precedent est accepte par la route et
         * jete par le service. L'application n'a rien a corriger : la
         * cadence est une affaire de serveur, et le telephone peut emettre a
         * son rythme sans remplir la table.
         */
        $enregistre = $this->suivi->enregistrer($booking, $donnees);

        return ApiResponse::GET_DATA(['data' => ['enregistre' => $enregistre]]);
    }

    /** Le client proprietaire regarde son chauffeur approcher. */
    public function suivi(Request $request, string $uid): JsonResponse
    {
        $id = Cipher::Decrypt($uid);

        $booking = $id
            ? Booking::query()
                ->whereKey($id)
                ->where('user_id', $request->user()->id)
                ->first()
            : null;

        if (! $booking) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette réservation est introuvable');
        }

        $dernier = $this->suivi->dernierFrais($booking);

        return ApiResponse::GET_DATA([
            'data' => [
                // Nul quand aucune position n'existe, ou que la derniere est
                // trop vieille pour qu'on y envoie quelqu'un.
                'chauffeur' => $dernier ? [
                    'lat' => (float) $dernier->location_delivery['lat'],
                    'lng' => (float) $dernier->location_delivery['lng'],
                    'updated_at' => $dernier->created_at->toJSON(),
                ] : null,
                'pickup' => [
                    'lat' => $booking->pickup_lat !== null ? (float) $booking->pickup_lat : null,
                    'lng' => $booking->pickup_lng !== null ? (float) $booking->pickup_lng : null,
                    'label' => $booking->pickup_location,
                ],
            ],
        ]);
    }
}
