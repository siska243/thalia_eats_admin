<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PrecommandeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'town' => ['required', 'string'],
            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*.uid' => ['required', 'string'],
            'products.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],

            'adresse' => ['required', 'array'],
            'adresse.adresse' => ['required', 'string', 'max:255'],
            'adresse.street' => ['nullable', 'string', 'max:255'],
            'adresse.number_street' => ['nullable', 'string', 'max:50'],
            'adresse.reference' => ['nullable', 'string', 'max:255'],

            'destinataire' => ['required', 'array'],
            'destinataire.name' => ['required', 'string', 'max:120'],
            'destinataire.phone' => ['required', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'town.required' => 'La ville de livraison est obligatoire.',
            'products.required' => 'Veuillez indiquer au moins un produit.',
            'products.max' => 'Une commande ne peut pas dépasser 100 produits différents.',
            'products.*.quantity.integer' => 'La quantité doit être un nombre entier.',
            'products.*.quantity.max' => 'La quantité ne peut pas dépasser 50 par produit.',
            'adresse.adresse.required' => 'L\'adresse de livraison est obligatoire.',
            'destinataire.required' => 'Veuillez indiquer qui doit être livré.',
            'destinataire.name.required' => 'Le nom de la personne à livrer est obligatoire.',
            'destinataire.phone.required' => 'Le numéro que le livreur appellera est obligatoire.',
        ];
    }
}
