<?php

namespace App\Http\Requests\Rental;

use App\Services\Rental\DepositPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ce que le client peut dire sur le paiement de son acompte.
 *
 * Ni montant ni devise : ils sont lus sur la reservation. Le seul champ libre
 * est le numero qui sera debite, et encore uniquement en mobile money — la
 * carte se saisit sur la page de la passerelle.
 */
class DepositRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'method' => ['nullable', Rule::in(DepositPayment::METHODES)],
            'phone' => ['required_if:method,mobile', 'nullable', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'method.in' => 'Ce moyen de paiement n\'est pas proposé.',
            'phone.required_if' => 'Le numéro à débiter est obligatoire.',
        ];
    }
}
