<?php

namespace App\Http\Requests\Rental;

use App\Services\Rental\BookingPricing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La consultation du catalogue de vehicules.
 *
 * Le creneau est facultatif, comme la liste des restaurants n'exige pas
 * d'heure de commande : on regarde d'abord les voitures, on choisit ses dates
 * ensuite, sur la fiche. L'exiger ici obligeait le client a composer un
 * creneau avant meme de savoir ce qui existe.
 *
 * Fourni, il fait calculer le prix du creneau et ne laisse que les vehicules
 * libres — c'est ce dont la fiche a besoin.
 */
class VehicleSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Les deux vont ensemble : un debut sans fin ne decrit pas un
            // creneau, et le prix ne pourrait pas etre calcule.
            'starts_at' => ['nullable', 'required_with:ends_at', 'date', 'after:now'],
            'ends_at' => ['nullable', 'required_with:starts_at', 'date', 'after:starts_at'],
            'search' => ['nullable', 'string', 'max:120'],
            // Le mode de tarification demande. Absent, c'est l'heure : le
            // comportement d'un appelant qui ne le connait pas ne change pas.
            'pricing_mode' => ['nullable', Rule::in(BookingPricing::MODES)],
            /*
             * La commune de prise en charge, pour savoir ce qui est
             * RESERVABLE. Facultative, et elle ne filtre rien : le catalogue
             * reste mondial, chaque vehicule dit simplement s'il opere ici.
             */
            'pickup_town_id' => ['nullable', 'integer', 'exists:towns,id'],
            /*
             * Le type de course demande, et l'option parking.
             *
             * Ils changent le PRIX : sans eux, la fiche annoncerait un montant
             * que la reservation ne tiendrait pas, et le client croirait qu'on
             * a change le prix au moment de payer.
             */
            'course_type_id' => ['nullable', 'integer', 'exists:course_types,id'],
            'parking' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'starts_at.required_with' => 'Indiquez à quelle heure vous souhaitez partir.',
            'starts_at.after' => 'La date de départ doit être à venir.',
            'ends_at.required_with' => 'Indiquez à quelle heure vous comptez rendre le véhicule.',
            'ends_at.after' => 'La fin doit suivre le début.',
        ];
    }
}
