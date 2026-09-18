<?php

namespace App\Http\Controllers\Oauth;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Le document de découverte du serveur d'autorisation (RFC 8414).
 *
 * C'est la première chose qu'un assistant lit : il y apprend où demander une
 * autorisation, où échanger un code, et surtout qu'il peut s'enregistrer tout
 * seul. Sans ce document, la connexion « en un clic » n'a pas de premier clic.
 */
class MetadonneesController extends Controller
{
    public function show(): JsonResponse
    {
        $emetteur = rtrim((string) config('app.url'), '/');

        return response()->json([
            // L'émetteur doit être identique, caractère pour caractère, à
            // celui annoncé par le connecteur MCP dans son défi 401.
            'issuer' => $emetteur,

            'authorization_endpoint' => $emetteur.'/oauth/authorize',
            'token_endpoint' => $emetteur.'/oauth/token',
            'registration_endpoint' => $emetteur.'/oauth/register',

            'scopes_supported' => TokenAbility::agent(),

            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],

            // S256 et rien d'autre. `plain` transmet le vérificateur en clair
            // dans l'URL d'autorisation : il ne protège de rien.
            'code_challenge_methods_supported' => ['S256'],

            // Clients publics uniquement : aucun secret n'est délivré, donc
            // aucun n'est attendu au point de jeton.
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);
    }
}
