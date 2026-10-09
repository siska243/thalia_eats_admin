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
    /*
     * Rend null plutot qu'une URL quand le nom de fichier est vide. Les
     * Resources testent `$this->image ? … : null`, ce qui laisse passer la
     * chaine vide : l'API annoncait alors `/images/`, l'application chargeait
     * cette adresse et recevait un 404, visible dans l'inspecteur ngrok. Le
     * test est fait ici, a la definition, et non dans chacune des cinq
     * Resources qui appellent ce helper.
     */
    public static function make(Request $request, ?string $fichier): ?string
    {
        $fichier = trim((string) $fichier, " \t\n\r\0\x0B/");

        if ($fichier === '') {
            return null;
        }

        return $request->getSchemeAndHttpHost().'/images/'.$fichier;
    }
}
