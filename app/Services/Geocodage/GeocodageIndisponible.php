<?php

namespace App\Services\Geocodage;

use RuntimeException;

/**
 * Le service de geocodage a refuse de repondre.
 *
 * A NE PAS CONFONDRE avec une adresse introuvable, qui rend simplement
 * `null`. La difference compte pour celui qui est devant l'ecran : une
 * adresse inconnue se corrige en la precisant, un service indisponible ne se
 * corrige pas du tout, et lui dire « adresse introuvable » l'enverrait
 * reecrire une adresse qui etait juste.
 *
 * Le cas est arrive le 17 octobre : Nominatim rendait 403 parce que le
 * contact declare etait `hello@example.com`, et l'administration affichait
 * « Adresse introuvable ».
 */
class GeocodageIndisponible extends RuntimeException {}
