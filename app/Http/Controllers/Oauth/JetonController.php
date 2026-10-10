<?php

namespace App\Http\Controllers\Oauth;

use App\Enums\TokenAbility;
use App\Http\Controllers\Api\AssistantTokenController;
use App\Http\Controllers\Controller;
use App\Models\OauthAuthorizationCode;
use App\Models\OauthClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * L'échange du code contre un jeton.
 *
 * Le jeton délivré est un jeton Sanctum ORDINAIRE, portant
 * `TokenAbility::agent()`. C'est ce qui fait que la connexion reste réversible
 * depuis le téléphone : elle apparaît dans la même liste que celles créées à la
 * main, et se révoque par le même bouton.
 */
class JetonController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if ($request->input('grant_type') !== 'authorization_code') {
            return $this->refus('unsupported_grant_type', 'Seul authorization_code est accepté.');
        }

        $clientId = $request->input('client_id');

        $client = is_string($clientId) && $clientId !== ''
            ? OauthClient::query()->where('client_id', $clientId)->first()
            : null;

        if (! $client) {
            return $this->refus('invalid_client', "Ce client n'est pas enregistré.", 401);
        }

        $codeEnClair = $request->input('code');
        $verifier = $request->input('code_verifier');
        $redirectUri = $request->input('redirect_uri');

        if (! is_string($codeEnClair) || ! is_string($verifier) || ! is_string($redirectUri)) {
            return $this->refus('invalid_request', 'Paramètres manquants.');
        }

        // La consommation du code et la délivrance du jeton sont dans la même
        // transaction, avec un verrou sur la ligne : deux requêtes simultanées
        // portant le même code ne peuvent pas produire deux jetons. Sans le
        // verrou, les deux liraient « non consommé » avant que l'une écrive.
        $resultat = DB::transaction(function () use ($codeEnClair, $verifier, $redirectUri, $client, $request) {
            $code = OauthAuthorizationCode::query()
                ->where('code_hash', OauthAuthorizationCode::empreinte($codeEnClair))
                ->lockForUpdate()
                ->first();

            // Toutes ces vérifications échouent de la même façon : dire
            // laquelle apprendrait à un attaquant ce qu'il lui manque.
            if (! $code
                || $code->consumed_at !== null
                || $code->expires_at->isPast()
                || $code->oauth_client_id !== $client->id
                || ! hash_equals($code->redirect_uri, $redirectUri)
                || ! $this->ressourceConcorde($code, $request->input('resource'))
                || ! $this->pkceConcorde($code->code_challenge, $verifier)) {
                return null;
            }

            // Le compte a pu disparaitre entre l'autorisation et l'echange.
            // Sans cette garde, l'appel suivant leverait une erreur sur null et
            // rendrait un 500 depuis l'interieur de la transaction, la ou un
            // refus ordinaire est la bonne reponse.
            $utilisateur = $code->user;

            if ($utilisateur === null) {
                return null;
            }

            $code->consumed_at = now();
            $code->save();

            $jours = AssistantTokenController::JOURS_PAR_DEFAUT;

            // Les capacites reellement accordees : l'intersection entre ce que
            // le client a demande et ce que Thalia delivre. `scopes` a deja ete
            // valide comme un sous-ensemble a l'autorisation, et vaut les cinq
            // capacites quand le client n'a rien demande de precis. Delivrer
            // systematiquement les cinq donnerait plus que ce que la page de
            // consentement a annonce.
            $capacites = array_values(array_intersect($code->scopes, TokenAbility::agent()));

            return [
                'jeton' => $utilisateur->createToken(
                    $client->client_name,
                    $capacites,
                    now()->addDays($jours),
                ),
                'secondes' => $jours * 86400,
                'capacites' => $capacites,
            ];
        });

        if ($resultat === null) {
            return $this->refus('invalid_grant', "Ce code d'autorisation n'est pas utilisable.");
        }

        // Un jeton porteur ne se met jamais en cache : ni le navigateur, ni un
        // proxy, ni le disque de qui que ce soit.
        return response()->json([
            'access_token' => $resultat['jeton']->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $resultat['secondes'],
            'scope' => implode(' ', $resultat['capacites']),
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /**
     * PKCE : `base64url(sha256(code_verifier))` doit redonner le défi.
     *
     * C'est ce qui remplace le secret client. Sans lui, quiconque intercepte le
     * code — dans un journal de proxy, dans l'historique du navigateur — peut
     * l'échanger lui-même.
     */
    private function pkceConcorde(string $challenge, string $verifier): bool
    {
        // Longueurs imposées par la RFC 7636 : un vérificateur trop court ne
        // serait pas hors de portée d'une recherche exhaustive.
        if (preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) !== 1) {
            return false;
        }

        $calcule = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $calcule);
    }

    /**
     * RFC 8707. Quand l'échange désigne une ressource, elle doit être celle
     * pour laquelle le client a demandé l'autorisation.
     *
     * Un échange qui n'en désigne aucune est accepté : le code reste lié à la
     * ressource de son autorisation, et refuser ici casserait les clients qui
     * n'envoient l'indicateur qu'à l'autorisation.
     *
     * ATTENTION à ce que ce contrôle N'EST PAS : il vérifie la cohérence entre
     * l'autorisation et l'échange, c'est de la tenue de registre. Il ne
     * restreint pas l'audience du jeton délivré, qui reste un jeton Sanctum
     * valable partout où ses capacités le portent. Donner une audience aux
     * jetons toucherait toute l'application, et ne se décide pas ici.
     */
    private function ressourceConcorde(OauthAuthorizationCode $code, mixed $resource): bool
    {
        if ($resource === null) {
            return true;
        }

        return is_string($resource) && hash_equals((string) $code->resource, $resource);
    }

    private function refus(string $code, string $description, int $statut = 400): JsonResponse
    {
        return response()->json([
            'error' => $code,
            'error_description' => $description,
        ], $statut)->header('Cache-Control', 'no-store');
    }
}
