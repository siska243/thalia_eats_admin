<?php

namespace App\Http\Controllers\Api\Rental;

use App\Http\Controllers\Controller;
use App\Http\Resources\Rental\CourseChauffeurResource;
use App\Models\Booking;
use App\Models\Chauffeur;
use App\Services\Rental\BookingWorkflow;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
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
    public function __construct(private readonly BookingWorkflow $workflow) {}

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

    /**
     * Le client est monte : le chauffeur saisit le code, la course demarre.
     *
     * Le code n'est jamais rendu a ce controleur ni a l'ecran du chauffeur.
     * Il arrive ici parce que le client le lui a lu, et le serveur compare.
     *
     * Les trois issues sont distinctes pour l'ecran : code faux, saisie
     * verrouillee, ou depart. Un message unique obligerait le chauffeur a
     * deviner s'il doit reessayer ou appeler l'administration.
     */
    public function depart(Request $request, string $uid): JsonResponse
    {
        $donnees = $request->validate([
            'code' => ['required', 'string', 'size:4'],
        ]);

        $course = $this->courseDuChauffeur($request, $uid);

        if (! $course) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette course est introuvable');
        }

        if ($course->statutEffectif() !== Booking::STATUS_CONFIRMED) {
            return ApiResponse::BAD_REQUEST(
                'statut',
                'Oups',
                "Cette course n'attend pas de départ.",
            );
        }

        $issue = $this->workflow->demarrerAvecCode($course, $donnees['code']);

        if ($issue === BookingWorkflow::DEPART_VERROUILLE) {
            return ApiResponse::BAD_REQUEST(
                'verrouille',
                'Oups',
                'Trop de codes erronés. Contactez Thalia pour débloquer cette course.',
            );
        }

        if ($issue === BookingWorkflow::DEPART_CODE_FAUX) {
            return ApiResponse::BAD_REQUEST(
                'code_faux',
                'Oups',
                'Ce code ne correspond pas. Demandez au client de vous le relire.',
            );
        }

        return ApiResponse::SUCCESS_DATA(
            new CourseChauffeurResource($course->fresh(['vehicle.currency', 'currency', 'user'])),
            'Course démarrée',
            'Bonne route.',
        );
    }

    /**
     * Le solde est remis en especes, la course se termine.
     *
     * L'encaissement laisse une ligne de paiement, c'est `collectBalance()`
     * qui s'en charge : sans elle, l'argent remis au chauffeur n'a aucune
     * trace et le rapprochement de fin de journee est impossible.
     *
     * Seule une course qui roule peut etre encaissee : sur une course pas
     * encore demarree, le chauffeur encaisserait un client qui n'est jamais
     * monte.
     */
    public function encaisser(Request $request, string $uid): JsonResponse
    {
        $chauffeur = $this->chauffeur($request);
        $course = $chauffeur ? $this->courseDuChauffeur($request, $uid) : null;

        if (! $course || ! $chauffeur) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette course est introuvable');
        }

        if ($course->statutEffectif() !== Booking::STATUS_IN_PROGRESS) {
            return ApiResponse::BAD_REQUEST(
                'statut',
                'Oups',
                "Cette course n'est pas en cours.",
            );
        }

        $this->workflow->collectBalance($course, $chauffeur);

        return ApiResponse::SUCCESS_DATA(
            new CourseChauffeurResource($course->fresh(['vehicle.currency', 'currency', 'user'])),
            'Solde encaissé',
            'La course est terminée.',
        );
    }

    /** Une course de ce chauffeur, ou rien : l'uid seul n'autorise pas. */
    private function courseDuChauffeur(Request $request, string $uid): ?Booking
    {
        $chauffeur = $this->chauffeur($request);
        $id = $chauffeur ? Cipher::Decrypt($uid) : null;

        return $id
            ? Booking::query()
                ->whereKey($id)
                ->where('chauffeur_id', $chauffeur->id)
                ->first()
            : null;
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
