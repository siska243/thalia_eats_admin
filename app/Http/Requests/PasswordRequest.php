<?php

namespace App\Http\Requests;

use App\Wrappers\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class PasswordRequest extends FormRequest
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
            'current_password'=>'min:4|required',
            'password'=>'required|min:4',
            'confirm_password'=>'same:password',
        ];
    }

    public function failedValidation(Validator $validator){

        return ApiResponse::BAD_REQUEST($validator->errors(),'Oups','Veuillez reverifier les informations envoyées');
    }

    public function withValidator($validator)
{
    // checks user current password
    // before making changes
    $validator->after(function ($validator) {
        if ( !Hash::check($this->current_password, $this->user()->password) ) {
            $validator->errors()->add('current_password', 'Your current password is incorrect.');
        }
    });
    return;
 }
    public function messages()
    {
        return [
            'current_password.required' => "Le mot de passe est obligatoire",
            'password.required' => "Le mot de passe est obligatoire",
            'current_password.min' => "Le mot de passe doit contenir au moins 3 lettre",
            'password.min' => "Le mot de passe doit contenir au moins 4 caracteres",
            'confirm_password.same' => "Les mots de passe ne correspondent pas"
        ];
    }
}
