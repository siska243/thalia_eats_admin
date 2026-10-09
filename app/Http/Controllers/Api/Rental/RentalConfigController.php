<?php

namespace App\Http\Controllers\Api\Rental;

use App\Http\Controllers\Controller;
use App\Models\Town;
use App\Settings\RentalSettings;
use App\Wrappers\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Ce que l'application doit savoir avant d'afficher quoi que ce soit.
 *
 * Les noms commerciaux et la zone desservie changent sans livraison : les
 * ecrire en dur dans le mobile obligerait a un build EAS pour ouvrir une
 * commune. L'application les lit ici, au demarrage.
 *
 * Public, comme le catalogue : on regarde ou le service va avant de se creer
 * un compte pour y reserver.
 */
class RentalConfigController extends Controller
{
    public function __invoke(RentalSettings $settings): JsonResponse
    {
        $towns = Town::query()
            ->where('rental_available', true)
            ->orderBy('title')
            ->get(['id', 'slug', 'title']);

        return ApiResponse::GET_DATA([
            'data' => [
                'brand_food' => $settings->brand_food,
                'brand_rental' => $settings->brand_rental,
                'whatsapp_number' => $settings->whatsapp_number,
                'out_of_zone_message' => $settings->out_of_zone_message,
                /*
                 * L'identifiant brut, pas un uid chiffre : il repart tel quel
                 * dans `pickup_town_id` a la reservation, et les communes sont
                 * publiques — il n'y a rien a cacher dans leur numero.
                 */
                'towns' => $towns,
            ],
        ]);
    }
}
