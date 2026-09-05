<?php

namespace App\Http\Middleware;

use App\Wrappers\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/**
 * Refuse la requête lorsqu'elle est portée par un jeton d'assistant.
 *
 * Un jeton applicatif porte l'ability « * » ; un jeton d'assistant porte une
 * liste étroite. Sans ce garde, un assistant pourrait s'émettre un second
 * jeton et contourner en une requête la restriction d'abilities.
 */
class EnsureNotAgentToken
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->tokenCan('*')) {
            return ApiResponse::BAD_REQUEST(
                'jeton_assistant',
                'Oups',
                'Cette action ne peut pas être effectuée depuis un assistant.'
            )->setStatusCode(403);
        }

        return $next($request);
    }
}
