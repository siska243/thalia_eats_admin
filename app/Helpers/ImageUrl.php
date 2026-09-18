<?php

namespace App\Helpers;

use Illuminate\Http\Request;

/**
 * Construit l'URL publique d'une image stockee dans /images.
 *
 * Les Resources la fabriquaient chacune de leur cote, en concatenant le
 * schema a la main :
 *
 *     'picture' => 'https://' . $request->server('HTTP_HOST') . '/images/' . $this->picture
 *
 * Ecrit ainsi, le schema est toujours `https`, quel que soit le serveur. En
 * production le site est en HTTPS et personne ne s'en apercoit. En local,
 * `php artisan serve` ecoute en HTTP sur 127.0.0.1:8000 : le navigateur
 * demandait alors `https://127.0.0.1:8000/images/...`, la connexion etait
 * fermee (ERR_CONNECTION_CLOSED) et aucune photo ne s'affichait.
 *
 * La meme ligne etait recopiee dans trois Resources — ProductResource,
 * RestaurantResource, SubCategoryProductResource — donc corriger l'une n'aurait
 * corrige qu'un tiers des images.
 *
 * `getSchemeAndHttpHost()` rend le schema reellement utilise par la requete :
 * `https` derriere le proxy de production, `http` en local. Le resultat est
 * identique a l'ancien en production, ce qui est le but : aucune URL ne change
 * pour les clients deja installes.
 */
class ImageUrl
{
    public static function make(Request $request, ?string $fichier): string
    {
        return $request->getSchemeAndHttpHost() . '/images/' . $fichier;
    }
}
