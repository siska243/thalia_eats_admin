<?php

namespace App\Http\Requests;

use App\Rules\TelephoneValide;
use App\Wrappers\ApiResponse;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AccountRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => 'email|required',
            'name' => 'required|min:3',
            'last_name' => 'required|min:3',

            /*
             * `UserAccountController` ecrit `$user->phone = $request->phone`
             * sans aucun controle. C'est AUSSI par ici que passe le numero
             * saisi a la reservation d'une course, qui le pose sur le compte :
             * un numero faux s'y installait, et le chauffeur appelait dans le
             * vide.
             */
            'phone' => ['nullable', new TelephoneValide],
        ];
    }

    /**
     * Meme defaut que `RegistrationRequest`, meme correction.
     *
     * La methode RENDAIT la reponse au lieu de la LEVER : Laravel ignore ce
     * retour, et la mise a jour du compte s'enregistrait quoi qu'on envoie.
     *
     * Les deux clients connus envoient deja `email`, `name` et `last_name` a
     * chaque mise a jour — c'est verifie dans leur code, pas suppose — donc
     * reveiller ces regles ne refuse rien qui passait hier.
     */
    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            ApiResponse::BAD_REQUEST($validator->errors(), 'Oups', 'Veuillez reverifier les informations envoyées')
        );
    }

    public function messages()
    {
        return [
            'email.required' => "L'email est obligatoire",
            'email.email' => 'Veuillez saisir un email valide',
            'name.required' => 'Le nom est obligatoire',
            'last_name.required' => 'Le prénom est obligatoire',
            'name.min' => 'Le nom doit contenir au moins 3 lettre',
            'last_name.min' => 'Le prénom doit contenir au moins 3 lettre',
        ];
    }
}
