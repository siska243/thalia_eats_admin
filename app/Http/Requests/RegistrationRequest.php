<?php

namespace App\Http\Requests;

use App\Wrappers\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class RegistrationRequest extends FormRequest
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
            'email'=>'email|unique:users,email|required',
            'name'=>'required|min:3',
            'last_name'=>'required|min:3',
            'password'=>'required|min:4',
            'confirm_password'=>'same:password'
        ];
    }

    public function failedValidation(Validator $validator){

        return ApiResponse::BAD_REQUEST($validator->errors(),'Oups','Veuillez reverifier les informations envoyées');
    }

    public function messages()
    {
        return [
            'email.required'=>"L'email est obligatoire",
            "email.unique"=>"cette email existe déjà",
            "email.email"=>"Veuillez saisir un email valide",
            'name.required'=>"Le nom est obligatoire",
            'last_name.required' => "Le prénom est obligatoire",
            'password.required' => "Le mot de passe est obligatoire",
            'name.min' => "Le nom doit contenir au moins 3 lettre",
            'last_name.min' => "Le prénom doit contenir au moins 3 lettre",
            'password.min' => "Le mot de passe doit contenir au moins 4 caracteres",
            'confirm_password.same' => "Les mots de passe ne correspondent pas"
        ];
    }
}
