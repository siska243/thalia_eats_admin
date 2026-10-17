<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Securite\DoubleAuthentification;
use App\Wrappers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * La double authentification, cote client : site et application.
 *
 * Elle s'active depuis le compte, jamais d'office. Le proprietaire : « c'est
 * a l'utilisateur de choisir », et les deux facteurs sont proposes.
 *
 * La regle est dans `DoubleAuthentification`, pas ici : l'administration
 * utilise celle de Filament sur LES MEMES COLONNES, et une seconde regle
 * ecrite dans ce controleur aurait fini par diverger de l'autre.
 */
class DoubleAuthentificationController extends Controller
{
    public function __construct(private readonly DoubleAuthentification $service) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::GET_DATA(['data' => $this->service->etat($request->user())]);
    }

    /**
     * Le QR code et le secret, SANS rien enregistrer.
     *
     * Tant que le client n'a pas prouve que son application lit le secret,
     * l'enregistrer verrouillerait son compte sur un QR jamais scanne.
     */
    public function preparer(Request $request): JsonResponse
    {
        if ($this->service->applicationActive($request->user())) {
            return ApiResponse::BAD_REQUEST(
                'deja_active',
                'Déjà configurée',
                'Une application d’authentification est déjà associée à ce compte. Désactivez-la d’abord.',
            );
        }

        return ApiResponse::GET_DATA(['data' => $this->service->preparerApplication($request->user())]);
    }

    public function activer(Request $request): JsonResponse
    {
        $valide = $request->validate([
            'secret' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $codes = $this->service->activerApplication(
            $request->user(),
            $valide['secret'],
            $valide['code'],
        );

        if ($codes === null) {
            return ApiResponse::BAD_REQUEST(
                'code_invalide',
                'Code incorrect',
                'Ce code ne correspond pas. Vérifiez l’heure de votre téléphone, puis réessayez.',
            );
        }

        /*
         * Les codes de secours ne sont rendus qu'ICI, une seule fois : ils
         * sont chiffres en base et aucune autre route ne les relit. Le client
         * doit les garder.
         */
        return ApiResponse::GET_DATA([
            'data' => [
                'codes_de_secours' => $codes,
                'etat' => $this->service->etat($request->user()->fresh()),
            ],
        ]);
    }

    /**
     * Desactiver exige le MOT DE PASSE.
     *
     * Sans cela, un telephone laisse deverrouille suffirait a retirer le
     * second facteur — ce dont il protege, precisement.
     */
    public function desactiver(Request $request): JsonResponse
    {
        $valide = $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($valide['password'], $request->user()->password)) {
            return ApiResponse::BAD_REQUEST(
                'mot_de_passe_incorrect',
                'Mot de passe incorrect',
                'Saisissez votre mot de passe actuel pour désactiver la double authentification.',
            );
        }

        $this->service->desactiverApplication($request->user());

        return ApiResponse::GET_DATA(['data' => $this->service->etat($request->user()->fresh())]);
    }

    public function basculerCourriel(Request $request): JsonResponse
    {
        $valide = $request->validate(['actif' => ['required', 'boolean']]);

        // Eteindre exige aussi le mot de passe : c'est un retrait de
        // protection, exactement comme pour l'application.
        if (! $valide['actif']) {
            $mdp = $request->validate(['password' => ['required', 'string']]);

            if (! Hash::check($mdp['password'], $request->user()->password)) {
                return ApiResponse::BAD_REQUEST(
                    'mot_de_passe_incorrect',
                    'Mot de passe incorrect',
                    'Saisissez votre mot de passe actuel pour désactiver la double authentification.',
                );
            }
        }

        $this->service->basculerCourriel($request->user(), $valide['actif']);

        return ApiResponse::GET_DATA(['data' => $this->service->etat($request->user()->fresh())]);
    }
}
