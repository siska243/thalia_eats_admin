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

            /*
             * Les adresses de retour dans l'application, apres le passage chez
             * la passerelle. Le paiement d'une commande de repas les transmet
             * de la meme facon, et c'est ce qui ramene le client dans l'app
             * plutot que sur le site.
             *
             * Pas de regle `url` : ce sont des liens profonds, du genre
             * `thalia-eats-app://…` ou `exp://192.168.1.6:8081/--/…` en
             * developpement. La validation d'URL de Laravel les rejetterait et
             * casserait le paiement la ou il fonctionne aujourd'hui.
             *
             * L'adresse du webhook, elle, ne vient JAMAIS de la requete : elle
             * est lue dans la configuration. La laisser venir du client
             * reviendrait a offrir un POST serveur vers l'hote de son choix.
             */
            'success_url' => ['nullable', 'string', 'max:2048'],
            'error_url' => ['nullable', 'string', 'max:2048'],
            'cancel_url' => ['nullable', 'string', 'max:2048'],
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
