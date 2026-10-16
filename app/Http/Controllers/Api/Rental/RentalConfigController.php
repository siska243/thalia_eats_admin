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
                 * Les bornes de duree, que l'application applique a ses
                 * champs et que l'API refuse au-dela. Une seule source : le
                 * mobile les avait en dur, et le serveur acceptait ce que
                 * l'ecran interdisait.
                 */
                'duree' => [
                    'hours_min' => $settings->hours_min,
                    'hours_max' => $settings->hours_max,
                    'days_min' => $settings->days_min,
                    'days_max' => $settings->days_max,
                    /*
                     * Le delai de prevenance, pour que l'ecran grise les
                     * creneaux trop proches au lieu de laisser le client
                     * composer une demande que l'API refusera.
                     */
                    'lead_time_minutes' => $settings->lead_time_minutes,
                ],
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
