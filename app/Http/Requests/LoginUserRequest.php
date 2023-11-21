<?php

namespace App\Http\Requests;

use App\Wrappers\ApiResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

class LoginUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => 'exists:users,email|required|email',
            'password' => 'required|min:4',
        ];
    }

    public function failedValidation(Validator $validator)
    {

        return ApiResponse::BAD_REQUEST($validator->errors(), 'Oups', 'Veuillez reverifier les informations envoyées');
    }

    public function messages()
    {
        return [
            'email.required' => "L'email est obligatoire",
            "email.email" => "Veuillez saisir un email valide",
            'password.required' => "Le mot de passe est obligatoire",
            'password.min' => "Le mot de passe doit contenir au moins 4 caracteres",
        ];
    }
}
