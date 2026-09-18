<?php

namespace App\Http\Requests\Rental;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La creation d'une reservation.
 *
 * Aucun montant n'est accepte : le prix est calcule par le serveur a partir du
 * vehicule et du creneau. Le laisser venir du client avait deja ouvert une
 * faille de paiement sur les commandes de repas.
 */
class BookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vehicle' => ['required', 'string'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'pickup_location' => ['required', 'string', 'max:255'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff_location' => ['nullable', 'string', 'max:255'],
            'dropoff_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'vehicle.required' => 'Choisissez un véhicule.',
            'starts_at.after' => 'La date de départ doit être à venir.',
            'ends_at.after' => 'La fin doit suivre le début.',
            'pickup_location.required' => 'Indiquez où le chauffeur doit vous prendre en charge.',
        ];
    }
}
