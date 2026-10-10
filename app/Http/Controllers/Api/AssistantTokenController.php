<?php

namespace App\Http\Controllers\Api;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssistantTokenRequest;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantTokenController extends Controller
{
    /**
     * Publique parce que le serveur OAuth délivre le MÊME type de connexion :
     * une seule définition de la durée, pas deux qui divergeront.
     */
    public const JOURS_PAR_DEFAUT = 90;

    public function store(AssistantTokenRequest $request): JsonResponse
    {
        $jours = (int) ($request->input('jours') ?: self::JOURS_PAR_DEFAUT);

        $token = $request->user()->createToken(
            $request->input('name'),
            TokenAbility::agent(),
            now()->addDays($jours),
        );

        // Le jeton en clair n'est renvoyé qu'ici, une seule fois. Il n'est
        // stocké nulle part en clair et ne peut pas être réaffiché.
        return ApiResponse::SUCCESS_DATA(
            [
                'uid' => Cipher::Encrypt($token->accessToken->id),
                'name' => $token->accessToken->name,
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at,
            ],
            'Connexion créée',
            'Copiez ce jeton maintenant : il ne sera plus jamais affiché.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $connexions = $request->user()->tokens()
            ->whereJsonDoesntContain('abilities', '*')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($token) => [
                'uid' => Cipher::Encrypt($token->id),
                'name' => $token->name,
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ])
            ->values();

        return ApiResponse::GET_DATA($connexions);
    }

    public function destroy(Request $request, string $uid): JsonResponse
    {
        $id = Cipher::Decrypt($uid);

        if ($id === false || ! ctype_digit((string) $id)) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette connexion est introuvable');
        }

        // Restreint aux jetons de l'utilisateur courant : révoquer celui d'un
        // autre doit être indiscernable d'un identifiant inexistant.
        $token = $request->user()->tokens()->where('id', (int) $id)->first();

        if (! $token) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette connexion est introuvable');
        }

        $token->delete();

        return ApiResponse::GET_DATA([
            'message' => 'La connexion a été révoquée.',
        ]);
    }
}
