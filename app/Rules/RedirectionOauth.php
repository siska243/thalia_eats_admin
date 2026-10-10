<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Une URI de redirection acceptable à l'enregistrement d'un client.
 *
 * C'est la seule barrière entre « un logiciel s'enregistre tout seul » et « un
 * code d'autorisation part chez n'importe qui ». On exige donc `https://`, avec
 * une unique exception, celle que la spec OAuth 2.1 prévoit pour les logiciels
 * installés sur la machine de l'utilisateur : la boucle locale, sur un port que
 * le système attribue au lancement — c'est ce que fait un client de bureau.
 *
 * Tout le reste est refusé : pas de `http://` distant (le code passerait en
 * clair), pas de fragment (interdit par la spec), pas de schéma exotique.
 */
class RedirectionOauth implements ValidationRule
{
    /**
     * Les hôtes pour lesquels `http://` reste acceptable. `localhost` n'est
     * volontairement pas résolu : c'est la chaîne littérale qui est autorisée.
     *
     * `parse_url` rend l'adresse IPv6 AVEC ses crochets (`[::1]`) : lister
     * aussi `::1` serait du code mort, jamais atteint.
     */
    private const HOTES_LOCAUX = ['127.0.0.1', '[::1]', 'localhost'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('Une URI de redirection doit être une chaîne.');

            return;
        }

        $parties = parse_url($value);

        if ($parties === false || ! isset($parties['scheme'], $parties['host'])) {
            $fail('Une URI de redirection doit être absolue.');

            return;
        }

        // Interdit par OAuth 2.1 : le fragment n'est pas envoyé au serveur, il
        // ne peut donc pas être comparé, et il sert à masquer la vraie cible.
        if (isset($parties['fragment']) || str_contains($value, '#')) {
            $fail('Une URI de redirection ne peut pas porter de fragment.');

            return;
        }

        $schema = strtolower($parties['scheme']);
        $hote = strtolower($parties['host']);

        if ($schema === 'https') {
            return;
        }

        if ($schema === 'http' && in_array($hote, self::HOTES_LOCAUX, true)) {
            return;
        }

        $fail('Une URI de redirection doit être en https, sauf sur la boucle locale.');
    }
}
