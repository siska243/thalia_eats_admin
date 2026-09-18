<?php

namespace App\Enums;

/**
 * Les abilities qu'un jeton confié à un assistant peut porter.
 *
 * Volontairement absentes, et qui ne doivent jamais y entrer : annuler une
 * commande, en modifier une en cours, changer une adresse de livraison,
 * déclencher un paiement, émettre un jeton. Un agent ne s'auto-délivre pas
 * de pouvoirs.
 */
enum TokenAbility: string
{
    case CatalogueLire = 'catalogue:lire';

    case DevisCalculer = 'devis:calculer';

    case PrecommandeCreer = 'precommande:creer';

    case PrecommandeLire = 'precommande:lire';

    case CommandeLire = 'commande:lire';

    /**
     * @return array<int, string>
     */
    public static function agent(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
