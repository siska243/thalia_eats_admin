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
    /** Part du total demandee a la reservation, avant confirmation. */
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

    /**
     * Le numero joint depuis le bouton WhatsApp, au format international.
     *
     * Hors des communes desservies, il n'y a pas de reservation a prendre :
     * le client parle a quelqu'un. Le numero change de main plus souvent que
     * l'application ne se livre.
     */
    public string $whatsapp_number;

    /** Le nom commercial de la restauration, affiche dans l'application. */
    public string $brand_food;

    /** Le nom commercial de la location, affiche dans l'application. */
    public string $brand_rental;

    /**
     * La phrase montree quand la commune de depart n'est pas desservie.
     *
     * Elle accompagne le bouton WhatsApp et sert aussi de message de refus a
     * l'API : une seule formulation, modifiable sans livrer.
     */
    public string $out_of_zone_message;

    /**
     * Les bornes de duree d'une reservation, par mode de tarification.
     *
     * Elles sont commerciales, pas techniques : au-dela de 23 heures une
     * course est une journee, et une journee coute bien moins cher que 24
     * heures facturees a l'heure. Ecrites en dur dans le mobile, elles
     * n'auraient protege que l'ecran — l'API, elle, acceptait la reservation
     * de 24 heures au tarif horaire. Elles vivent donc ici et le serveur les
     * applique.
     */
    public int $hours_min;

    /** Plafond horaire. 24 heures est une journee, le maximum reste 23. */
    public int $hours_max;

    public int $days_min;

    public int $days_max;

    public static function group(): string
    {
        return 'rental';
    }
}
