<?php

namespace App\Http\Controllers\Oauth;

use App\Http\Controllers\Controller;
use App\Models\OauthClient;
use App\Rules\RedirectionOauth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * L'enregistrement dynamique de client (RFC 7591).
 *
 * C'est ce qui permet qu'aucun humain ne saisisse jamais d'identifiant ni de
 * secret : l'assistant s'annonce, reçoit un `client_id`, et s'en sert. Le
 * revers est que n'importe qui peut appeler cette route — d'où le limiteur, et
 * d'où la sévérité sur les `redirect_uris`, qui sont la seule chose que cet
 * enregistrement fige vraiment.
 */
class EnregistrementController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            // `Model::unguard()` est global dans cette application : cette
            // liste de règles est la SEULE liste blanche. Rien d'autre que les
            // champs nommés ici ne doit atteindre le modèle.
            $valide = $request->validate([
                'client_name' => ['required', 'string', 'max:255'],
                'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
                'redirect_uris.*' => ['required', 'string', 'max:2048', new RedirectionOauth],
                // La RFC 7591 laisse le serveur restreindre ce qu'un client
                // demande, et l'en informer par ce qu'il renvoie. On exige donc
                // seulement que ce qu'on sait faire figure dans la demande :
                // un client qui réclame aussi `refresh_token` s'enregistre, et
                // lit dans la réponse qu'il n'en aura pas.
                'grant_types' => ['sometimes', 'array'],
                'grant_types.*' => ['string'],
                'response_types' => ['sometimes', 'array'],
                'response_types.*' => ['string'],

                // Celle-ci reste stricte : un client qui compte présenter un
                // secret doit apprendre tout de suite qu'il n'en recevra pas,
                // plutôt que d'échouer plus tard sans comprendre.
                'token_endpoint_auth_method' => ['sometimes', 'string', 'in:none'],
            ]);
        } catch (ValidationException $e) {
            return $this->refus('invalid_client_metadata', $e->validator->errors()->first());
        }

        // `array_key_exists` sur les données validées, et non `$request->has()` :
        // la clé n'est ici que si elle a passé la validation.
        if (array_key_exists('grant_types', $valide) && ! in_array('authorization_code', $valide['grant_types'], true)) {
            return $this->refus('invalid_client_metadata', 'Seul le flux authorization_code est proposé.');
        }

        if (array_key_exists('response_types', $valide) && ! in_array('code', $valide['response_types'], true)) {
            return $this->refus('invalid_client_metadata', 'Seul le type de réponse « code » est proposé.');
        }

        $client = new OauthClient;
        $client->client_id = 'thalia-'.Str::random(40);
        $client->client_name = $valide['client_name'];
        $client->redirect_uris = array_values($valide['redirect_uris']);
        $client->grant_types = ['authorization_code'];
        $client->response_types = ['code'];
        $client->token_endpoint_auth_method = 'none';
        $client->save();

        // Aucun `client_secret` : le client est public. En délivrer un ferait
        // croire, au prochain qui lit ce code, qu'une vérification a lieu.
        return response()->json([
            'client_id' => $client->client_id,
            'client_id_issued_at' => $client->created_at->getTimestamp(),
            'client_name' => $client->client_name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => $client->grant_types,
            'response_types' => $client->response_types,
            'token_endpoint_auth_method' => $client->token_endpoint_auth_method,
        ], 201)->header('Cache-Control', 'no-store');
    }

    private function refus(string $code, string $description): JsonResponse
    {
        return response()->json([
            'error' => $code,
            'error_description' => $description,
        ], 400)->header('Cache-Control', 'no-store');
    }
}
