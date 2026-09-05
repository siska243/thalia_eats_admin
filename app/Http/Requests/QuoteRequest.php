<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuoteRequest extends FormRequest
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
            'restaurant' => ['nullable', 'string'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.uid' => ['required', 'string'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
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
            'products.min' => 'Veuillez indiquer au moins un produit.',
            'products.*.uid.required' => 'Chaque produit doit porter un identifiant.',
            'products.*.quantity.required' => 'Chaque produit doit porter une quantité.',
            'products.*.quantity.min' => 'La quantité doit être au moins égale à 1.',
        ];
    }
}
