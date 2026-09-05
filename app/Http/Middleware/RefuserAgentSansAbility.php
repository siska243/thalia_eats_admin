<?php

namespace App\Http\Middleware;

use App\Wrappers\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/**
 * Refuser par défaut : un jeton d'assistant n'atteint que les routes qui
 * déclarent explicitement une ability.
 *
 * Sans ce garde, une route protégée par le seul `auth:sanctum` est ouverte à
 * tout jeton authentifié, assistants compris — y compris une route mutante
 * ajoutée par quelqu'un qui ignore leur existence. Le cas s'est produit
 * pendant l'écriture de ce plan.
 *
 * L'inverse — énumérer les routes interdites — ne tient pas : il faudrait y
 * penser à chaque nouvelle route, et l'oubli est silencieux. Ici l'oubli est
 * bruyant et du bon côté : une route destinée aux agents mais non marquée
 * leur est fermée, ce qui se voit immédiatement.
 *
 * La sécurité de ce garde suppose que le groupe api reste sans session :
 * voir tests/Feature/Api/HypothesesDeSecuriteTest.php.
 *
 * Les deux alias comptent : `ability` (au moins une) et `abilities` (toutes).
 * Ne reconnaître que le premier fermerait silencieusement aux assistants une
 * route déclarant la variante « toutes », qui devrait leur être ouverte.
 */
class RefuserAgentSansAbility
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // Route publique, ou jeton applicatif : rien à faire.
        if (! $user || $user->tokenCan('*')) {
            return $next($request);
        }

        $declareUneAbility = collect($request->route()?->gatherMiddleware() ?? [])
            ->contains(fn ($middleware) => is_string($middleware) && (
                str_starts_with($middleware, 'ability:')
                || str_starts_with($middleware, 'abilities:')
            ));

        if (! $declareUneAbility) {
            return ApiResponse::BAD_REQUEST(
                'ability_absente',
                'Oups',
                'Cette action n\'est pas accessible depuis un assistant.'
            )->setStatusCode(403);
        }

        return $next($request);
    }
}
