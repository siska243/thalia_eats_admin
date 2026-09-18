<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Les reglages du module de location.
 *
 * Ces valeurs apparaissent dans le calcul du prix, dans l'ecran client, dans
 * l'administration et dans les messages WhatsApp. Une seconde copie
 * divergerait a la premiere negociation commerciale : elles vivent ici, une
 * fois, modifiables depuis l'administration sans deploiement.
 */
class RentalSettings extends Settings
{
    /** Part du total demandee a la reservation. Le devis fixe 10 %. */
    public float $deposit_percentage;

    /**
     * Part remboursee quand une reservation est annulee, ou perdue au profit
     * d'un client qui a paye avant.
     *
     * Le remboursement n'est pas execute en V1 : le montant du est calcule et
     * enregistre, le mouvement d'argent reste manuel.
     */
    public float $refund_percentage;

    /** Duree minimale facturee, en heures. Toute heure entamee est due. */
    public float $minimum_hours;

    /**
     * Essais autorises sur le code de prise en charge avant blocage.
     *
     * Le deblocage passe par l'administration : contrairement aux livreurs, il
     * n'y a pas de deverrouillage automatique au bout d'un delai.
     */
    public int $pickup_code_max_attempts;

    public static function group(): string
    {
        return 'rental';
    }
}
