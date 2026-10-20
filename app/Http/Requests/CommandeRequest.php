<?php

namespace App\Http\Requests;

use App\Rules\TelephoneValide;
use App\Wrappers\ApiResponse;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CommandeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
        // return auth()->user()->hasRole('clients');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'adresse' => 'required',
            'pricing' => 'required',
            'products' => [
                'uid' => 'required',
                'quantity' => 'required',
            ],

            /*
             * Le numero du destinataire, quand la commande est pour quelqu'un
             * d'autre. Il n'avait AUCUNE regle, et le controleur l'ecrit tel
             * quel sur la commande : c'est pourtant le seul numero que le
             * livreur composera, et le client qui commande ne sera pas
             * forcement joignable pour le corriger.
             */
            'recipient_phone' => ['nullable', new TelephoneValide],
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
            'products.required' => 'le produit est obligatoire',
            'pricing.required' => 'Le montant est obligatoire',
        ];
    }
}
