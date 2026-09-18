<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssistantTokenRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:60'],
            'jours' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Veuillez nommer cette connexion.',
            'name.max' => 'Le nom ne peut pas dépasser 60 caractères.',
            'jours.max' => 'Une connexion ne peut pas durer plus de 365 jours.',
        ];
    }
}
