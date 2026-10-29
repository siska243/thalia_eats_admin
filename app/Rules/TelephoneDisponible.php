<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Un numéro qui n'est pas déjà celui d'un autre compte.
 *
 * Le propriétaire, le 28 octobre : « lors de l'enregistrement on doit rendre
 * ça unique, pas dans la base mais dans le register ».
 *
 * À l'inscription, donc, et pas par un index : la base porte déjà onze
 * comptes qui partagent cinq numéros, hérités d'avant toute validation. Une
 * contrainte d'unicité refuserait la prochaine migration, et arbitrer ces
 * doublons est une décision métier qui n'est pas prise. Ils restent en
 * place ; la règle empêche seulement d'en créer de nouveaux.
 *
 * LA COMPARAISON NE SE FAIT PAS SUR LA CHAÎNE SAISIE. « 0821693466 » et
 * « +243 821 693 466 » sont le même abonné et deux chaînes différentes :
 * c'est `User::comptesAvecTelephone()` qui dit ce qu'est le même numéro, et
 * c'est la même définition que lit la connexion. Deux lectures séparées
 * laisseraient s'inscrire un numéro avec lequel on ne pourrait pas se
 * connecter.
 *
 * NE JUGE NI LE FORMAT NI LA PRÉSENCE : `TelephoneValide` et `nullable`
 * s'en chargent, à côté. Un numéro invalide n'appartient à personne, cette
 * règle le laisse donc passer et c'est l'autre qui le refuse.
 */
class TelephoneDisponible implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return;
        }

        if (User::comptesAvecTelephone((string) $value)->isNotEmpty()) {
            $fail('Ce numéro est déjà associé à un compte. Connectez-vous, ou utilisez un autre numéro.');
        }
    }
}
