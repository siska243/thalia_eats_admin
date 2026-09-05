<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BudgetSuggestionRequest extends FormRequest
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
            'budget' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string'],
            'town' => ['required', 'string'],
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string'],
            'sub_category' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'budget.required' => 'Veuillez indiquer votre budget.',
            'budget.min' => 'Le budget ne peut pas être négatif.',
            'currency.required' => 'Veuillez indiquer la devise de votre budget.',
            'town.required' => 'La ville de livraison est obligatoire.',
        ];
    }
}
