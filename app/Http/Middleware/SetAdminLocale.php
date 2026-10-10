<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Le back-office parle francais.
 *
 * Tout le domaine est en francais — libelles des Resources, aide des champs,
 * messages de l'API — mais Filament servait ses propres textes en anglais :
 * « Create Réservation », « Select an option », « Save changes ».
 *
 * La langue est posee ici plutot que dans `APP_LOCALE`. Le reglage global
 * changerait aussi les messages de validation renvoyes par l'API, que les
 * applications mobiles deja installees affichent telles quelles : une
 * amelioration du back-office n'a pas a modifier ce que voit un client sur son
 * telephone.
 */
class SetAdminLocale
{
    public function handle(Request $request, Closure $next)
    {
        App::setLocale('fr');

        return $next($request);
    }
}
