<?php

namespace App\Http\Requests\Rental;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La recherche de vehicules disponibles.
 *
 * Le creneau est obligatoire : afficher un catalogue sans savoir quand le
 * client veut partir revient a lui montrer des voitures qu'il ne pourra pas
 * reserver.
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
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'starts_at.required' => 'Indiquez à quelle heure vous souhaitez partir.',
            'starts_at.after' => 'La date de départ doit être à venir.',
            'ends_at.required' => 'Indiquez à quelle heure vous comptez rendre le véhicule.',
            'ends_at.after' => 'La fin doit suivre le début.',
        ];
    }
}
