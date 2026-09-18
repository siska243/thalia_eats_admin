<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\UserAdresse;
use App\Wrappers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les adresses que l'assistant propose au client.
 *
 * Deux sources, dans cet ordre : le carnet d'adresses s'il est renseigné — il
 * porte des libellés (« maison », « bureau ») qu'une adresse déduite d'une
 * commande n'aura jamais — puis les commandes passées en repli, pour le client
 * qui commande depuis des années sans avoir jamais ouvert l'écran d'adresses.
 */
class AdresseRecenteController extends Controller
{
    private const MAX = 10;

    public function index(Request $request): JsonResponse
    {
        $carnet = UserAdresse::query()
            ->with('town')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('is_main')
            ->orderByDesc('created_at')
            ->take(self::MAX)
            ->get()
            ->map(fn (UserAdresse $a) => [
                'adresse' => $a->adresse,
                'street' => $a->street,
                'number_street' => $a->number_street,
                'reference' => $a->reference,
                'town' => $a->town?->slug,
                'town_title' => $a->town?->title,
                'label' => $a->label,
                'source' => 'carnet',
                'derniere_utilisation' => null,
            ])
            ->values();

        if ($carnet->isNotEmpty()) {
            return ApiResponse::GET_DATA($carnet);
        }

        $adresses = Commande::query()
            ->with('town')
            ->where('user_id', $request->user()->id)
            ->whereNotNull('adresse_delivery')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['adresse_delivery', 'street', 'number_street', 'reference_adresse', 'town_id', 'created_at', 'id'])
            ->unique(fn (Commande $c) => $c->adresse_delivery.'|'.$c->street.'|'.$c->number_street.'|'.$c->town_id)
            ->take(self::MAX)
            ->map(fn (Commande $c) => [
                'adresse' => $c->adresse_delivery,
                'street' => $c->street,
                'number_street' => $c->number_street,
                'reference' => $c->reference_adresse,
                'town' => $c->town?->slug,
                'town_title' => $c->town?->title,
                'label' => null,
                'source' => 'commandes',
                'derniere_utilisation' => $c->created_at,
            ])
            ->values();

        return ApiResponse::GET_DATA($adresses);
    }
}
