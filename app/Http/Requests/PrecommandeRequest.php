<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Une adresse dictée à une machine est une adresse mal recopiée, et c'est le
 * livreur qui paie l'erreur. L'assistant ne collecte donc que la commune ; le
 * client saisit lui-même ses coordonnées sur la page du lien de paiement.
 */
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
        ];
    }
}
