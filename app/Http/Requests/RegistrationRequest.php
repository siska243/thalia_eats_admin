<?php

namespace App\Http\Requests;

use App\Rules\TelephoneValide;
use App\Wrappers\ApiResponse;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => 'email|unique:users,email|required',
            'name' => 'required|min:3',
            'last_name' => 'required|min:3',
            'password' => 'required|min:4',
            'confirm_password' => 'same:password',

            /*
             * Le numero entrait en base sans aucun controle : `AuthController`
             * ecrit `$request->phone` tel quel. Un `0000` passait, et c'est le
             * livreur qui le decouvrait.
             *
             * `nullable` et non `required` : certains clients deja installes
             * n'envoient pas ce champ, et l'exiger les arreterait tous. La
             * regle juge le format, pas la presence.
             */
            'phone' => ['nullable', new TelephoneValide],
        ];
    }

    /**
     * LA VALIDATION NE S'APPLIQUAIT PAS.
     *
     * Cette methode RENDAIT la reponse au lieu de la LEVER. Laravel ignore ce
     * retour : la requete poursuivait son chemin comme si tout etait valide,
     * et aucune des regles ci-dessus n'a jamais rien refuse. C'est ce qui
     * explique le constat du proprietaire — « tu verifies meme pas » — et
     * pourquoi `AuthController` re-teste l'existence du compte a la main : la
     * regle `unique` ne servait a rien.
     *
     * `HttpResponseException` est le seul moyen de rendre CETTE reponse-la
     * sans la remplacer par le 422 de Laravel : les deux clients lisent
     * l'enveloppe `{title, message, error}` et un autre format casserait
     * leurs messages.
     *
     * Corrige ici et sur `AccountRequest`, pas ailleurs : les trois autres
     * requetes qui portent le meme defaut reveilleraient des regles dont on
     * n'a pas verifie qu'elles correspondent a ce que les clients envoient —
     * et `LoginUserRequest` ouvrirait en prime un moyen de savoir quelles
     * adresses sont inscrites.
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
            'email.unique' => 'cette email existe déjà',
            'email.email' => 'Veuillez saisir un email valide',
            'name.required' => 'Le nom est obligatoire',
            'last_name.required' => 'Le prénom est obligatoire',
            'password.required' => 'Le mot de passe est obligatoire',
            'name.min' => 'Le nom doit contenir au moins 3 lettre',
            'last_name.min' => 'Le prénom doit contenir au moins 3 lettre',
            'password.min' => 'Le mot de passe doit contenir au moins 4 caracteres',
            'confirm_password.same' => 'Les mots de passe ne correspondent pas',
        ];
    }
}
