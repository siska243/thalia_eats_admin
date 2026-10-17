<?php

namespace App\Http\Controllers\Api\Rental;

use App\Http\Controllers\Controller;
use App\Models\CourseType;
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
                 * La part payee a la reservation. Elle etait ecrite en dur sur
                 * l'accueil du mobile — « 10 % » alors que le reglage dit 20 —
                 * et le proprietaire ne pouvait pas la corriger depuis
                 * l'administration. Le solde s'en deduit, personne ne le
                 * calcule deux fois.
                 */
                'deposit_percentage' => $settings->deposit_percentage,
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

                /*
                 * Les types de course EXISTANTS, pour les filtres du
                 * catalogue — « dans le filtre il faut ajouter le type de
                 * course ».
                 *
                 * A ne pas confondre avec le `course_types` d'un VEHICULE,
                 * qui dit ce que CE vehicule propose. Les deux ne peuvent pas
                 * diverger : la liste du vehicule est tiree de cette meme
                 * table, et celle-ci ne sert qu'a dresser les choix d'un
                 * filtre. Deux questions differentes, une seule source.
                 *
                 * Le libelle suffit : un filtre n'applique aucune regle, il
                 * restreint une liste. Les regles voyagent avec le vehicule.
                 */
                'course_types' => CourseType::query()->actifs()->get(['id', 'code', 'title']),
            ],
        ]);
    }
}
