<?php

namespace App\Http\Requests;

use App\Wrappers\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CommandeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->user()->hasRole('clients');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'adresse'=>'required',
            'pricing'=>'required',
            'products'=>[
                'uid'=>'required',
                'quantity'=>'required',
            ],
        ];
    }

    public function failedValidation(Validator $validator)
    {

        return ApiResponse::BAD_REQUEST($validator->errors(), 'Oups', 'Veuillez reverifier les informations envoyées');
    }

    public function messages()
    {
        return [
            'adresse.required' => "L'adresse est obligatoire",
            "products.required" => "le produit est obligatoire",
            'pricing.required' => "Le montant est obligatoire",
        ];
    }

}
