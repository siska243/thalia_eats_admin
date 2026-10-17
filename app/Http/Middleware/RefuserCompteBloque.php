<?php

namespace App\Http\Middleware;

use App\Wrappers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte bloque n'atteint aucune route de l'API.
 *
 * Bloquer revoque deja les jetons du compte (`User::bloquer()`), ce qui suffit
 * en pratique. Ce garde est la pour le cas ou un jeton survivrait — emis par
 * un autre chemin, restaure d'une sauvegarde, cree juste avant le blocage par
 * une requete concurrente. C'est la seule porte que TOUTES les routes
 * authentifiees traversent : la poser ici evite d'avoir a y penser route par
 * route.
 *
 * Les requetes anonymes ne sont pas concernees : sans utilisateur resolu, il
 * n'y a rien a bloquer, et le catalogue reste public.
 */
class RefuserCompteBloque
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if ($utilisateur?->estBloque()) {
            ApiResponse::FORBIDEN(
                'Compte bloqué',
                "Ce compte a été bloqué. Contactez le support si vous pensez qu'il s'agit d'une erreur.",
            );
        }

        return $next($request);
    }
}
