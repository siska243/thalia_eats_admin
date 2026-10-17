<?php

namespace App\Http\Requests\Rental;

use App\Models\CourseType;
use App\Services\Rental\BookingPricing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            /*
             * Pas de `after:now` ici : le depart est juge par
             * `BookingPricing`, qui tient le delai de prevenance. Deux regles
             * pour la meme question donneraient deux refus differents selon
             * qu'on est une minute ou vingt minutes trop tot, et c'est
             * exactement le motif qui a deja produit des bugs ici.
             */
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            // Le mode de tarification choisi. Absent, c'est l'heure.
            'pricing_mode' => ['nullable', Rule::in(BookingPricing::MODES)],
            'pickup_location' => ['required', 'string', 'max:255'],
            /*
             * Nullable : les applications deja installees n'envoient pas de
             * commune. Absente, la reservation passe — c'est le prix a payer
             * pour ne pas casser le parc existant.
             */
            'pickup_town_id' => ['nullable', 'exists:towns,id'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff_location' => ['nullable', 'string', 'max:255'],
            'dropoff_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['nullable', 'numeric', 'between:-180,180'],

            /*
             * Le type de course. Nullable pour la meme raison que la commune :
             * les applications deja installees n'en envoient pas, et leurs
             * reservations sont des courses urbaines ordinaires.
             */
            'course_type_id' => ['nullable', 'exists:course_types,id'],
            /*
             * Le commentaire est exige quand LE TYPE le demande, et c'est la
             * ligne du referentiel qui le dit — pas une liste de codes ecrite
             * ici, qui rendrait un quatrieme type impossible a regler sans
             * livraison.
             *
             * L'exigence vit dans la validation et non en base : une
             * reservation prise au comptoir par l'administration ne doit pas
             * etre bloquee par un commentaire manquant.
             */
            'course_note' => [
                Rule::requiredIf(fn () => (bool) CourseType::query()
                    ->whereKey($this->input('course_type_id'))
                    ->value('note_required')),
                'nullable', 'string', 'max:2000',
            ],
            'parking' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'vehicle.required' => 'Choisissez un véhicule.',
            'ends_at.after' => 'La fin doit suivre le début.',
            'pickup_location.required' => 'Indiquez où le chauffeur doit vous prendre en charge.',
            'pickup_town_id.exists' => 'Cette commune est inconnue.',
        ];
    }
}
