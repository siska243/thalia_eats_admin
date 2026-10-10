<?php

namespace App\Http\Controllers\Api\Rental;

use App\Http\Controllers\Controller;
use App\Http\Resources\Rental\CourseChauffeurResource;
use App\Models\Booking;
use App\Models\Chauffeur;
use App\Wrappers\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les ecrans du chauffeur de location : ses courses, son historique, son jour.
 *
 * Le chauffeur de location est **informatif**. Il n'accepte ni ne refuse :
 * l'affectation est faite par l'administration, et ces trois routes ne font
 * que lire. C'est la difference avec le livreur de repas, qui a un ecran de
 * demandes.
 *
 * L'autorisation ne vient d'aucun role mais d'une clause SQL : la fiche
 * `Chauffeur` liee au compte connecte, et `bookings.chauffeur_id`. Un compte
 * sans fiche ne voit rien, meme s'il porte le role ; un chauffeur ne voit que
 * ses propres courses, meme s'il forge un uid. Meme regle que sur
 * `BookingTrackingController`.
 */
class CourseChauffeurController extends Controller
{
    /**
     * Les courses a venir et celle qui roule.
     *
     * Triees par depart croissant : la prochaine en premier, c'est l'ordre
     * dans lequel il les fera. L'historique, lui, se lit a l'envers.
     */
    public function courses(Request $request): JsonResponse
    {
        $chauffeur = $this->chauffeur($request);

        if (! $chauffeur) {
            return ApiResponse::NOT_AUTHORIZED('Oups', "Ce compte n'est pas un chauffeur");
        }

        $courses = $this->requete($chauffeur)
            ->whereIn('status', Booking::COURSE_ENGAGEE)
            ->orderBy('starts_at')
            ->get();

        return ApiResponse::GET_DATA([
            'data' => CourseChauffeurResource::collection($courses),
        ]);
    }

    /** Les courses finies ou annulees, la derniere en premier. */
    public function historique(Request $request): JsonResponse
    {
        $chauffeur = $this->chauffeur($request);

        if (! $chauffeur) {
            return ApiResponse::NOT_AUTHORIZED('Oups', "Ce compte n'est pas un chauffeur");
        }

        $courses = $this->requete($chauffeur)
            ->whereIn('status', [
                Booking::STATUS_COMPLETED,
                Booking::STATUS_CANCELLED,
                Booking::STATUS_EXPIRED,
                Booking::STATUS_LOST,
            ])
            ->latest('starts_at')
            ->get();

        return ApiResponse::GET_DATA([
            'data' => CourseChauffeurResource::collection($courses),
        ]);
    }

    /**
     * Le tableau de bord : ce qu'il a a faire maintenant.
     *
     * Les comptes sont faits en SQL et non dans l'ecran : deux applications
     * qui additionnent chacune de leur cote finissent par annoncer deux
     * chiffres differents pour la meme journee.
     */
    public function resume(Request $request): JsonResponse
    {
        $chauffeur = $this->chauffeur($request);

        if (! $chauffeur) {
            return ApiResponse::NOT_AUTHORIZED('Oups', "Ce compte n'est pas un chauffeur");
        }

        $enCours = $this->requete($chauffeur)
            ->where('status', Booking::STATUS_IN_PROGRESS)
            ->orderBy('starts_at')
            ->first();

        $prochaine = $this->requete($chauffeur)
            ->where('status', Booking::STATUS_CONFIRMED)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first();

        return ApiResponse::GET_DATA([
            'data' => [
                'chauffeur' => [
                    'full_name' => $chauffeur->full_name,
                    'phone' => $chauffeur->phone,
                ],
                'en_cours' => $enCours ? new CourseChauffeurResource($enCours) : null,
                'prochaine' => $prochaine ? new CourseChauffeurResource($prochaine) : null,
                'courses_du_jour' => $this->requete($chauffeur)
                    ->whereIn('status', Booking::COURSE_ENGAGEE)
                    ->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()])
                    ->count(),
                'courses_terminees' => $this->requete($chauffeur)
                    ->where('status', Booking::STATUS_COMPLETED)
                    ->count(),
            ],
        ]);
    }

    /** La fiche chauffeur du compte connecte, ou rien. */
    private function chauffeur(Request $request): ?Chauffeur
    {
        return Chauffeur::query()
            ->where('user_id', $request->user()->id)
            ->first();
    }

    /** @return Builder<Booking> */
    private function requete(Chauffeur $chauffeur)
    {
        return Booking::query()
            ->where('chauffeur_id', $chauffeur->id)
            ->with(['vehicle.currency', 'currency', 'user']);
    }
}
