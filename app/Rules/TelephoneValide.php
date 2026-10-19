<?php

namespace App\Rules;

use App\Wrappers\LibPhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Un numéro de téléphone qu'on peut réellement appeler.
 *
 * Le propriétaire, le 20 octobre : « les gens écrivent n'importe quoi et tu
 * vérifies même pas si le numéro est correct ».
 *
 * C'était exact. Les FormRequest n'avaient aucune règle de format : au mieux
 * `string|max:30`, souvent rien du tout. Un `0000` entrait en base à
 * l'inscription, et le chauffeur ou le livreur découvrait sur le terrain qu'il
 * n'avait aucun moyen de joindre le client.
 *
 * LA VALIDATION ELLE-MÊME N'EST PAS ÉCRITE ICI. Elle vit dans
 * `LibPhoneNumber::checkValidationNumber()`, qui servait déjà aux chemins de
 * paiement. Cette règle ne fait que l'opposer à la saisie, pour que les deux
 * répondent la même chose : deux définitions de « ce numéro est valide »
 * finiraient par se contredire, et ce dépôt l'a déjà payé deux fois.
 *
 * Pays par défaut `CD` : un numéro saisi « 0810000001 », sans indicatif, est
 * lu comme congolais. Un numéro étranger reste accepté s'il est écrit au
 * format international.
 *
 * NE JUGE PAS LA PRÉSENCE. Un champ facultatif reste facultatif : c'est
 * `nullable` ou `required` qui le dit, à côté. Mêler les deux ferait d'un
 * champ vide une erreur de format, avec un message qui ne veut rien dire.
 */
class TelephoneValide implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            $fail('Ce numéro de téléphone n’est pas valide.');

            return;
        }

        if (! (new LibPhoneNumber((string) $value))->checkValidationNumber()) {
            $fail('Ce numéro de téléphone n’est pas valide. Exemple : 0810000000.');
        }
    }
}
