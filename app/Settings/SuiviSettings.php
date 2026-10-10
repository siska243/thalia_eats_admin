<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Les reglages du suivi de position, repas et location confondus.
 *
 * Ils ne sont pas techniques : ils arbitrent entre la finesse du trace et le
 * volume de la table. L'application livreur emet parfois plusieurs fois par
 * seconde, et le parc installe ne se met pas a jour sur commande — c'est donc
 * le serveur qui tranche, et il doit pouvoir le faire sans livraison.
 */
class SuiviSettings extends Settings
{
    /**
     * Le temps minimal entre deux releves d'une meme course, en minutes.
     *
     * Un point qui arrive plus tot est refuse, pas enregistre. Mesure sur la
     * commande 41 : dix-neuf releves, dont certains a une seconde d'ecart.
     */
    public int $interval_minutes;

    /**
     * Au-dela de ce silence, en minutes, la derniere position ne vaut plus.
     *
     * Montrer un chauffeur la ou il etait il y a une heure est pire que ne
     * rien montrer : le client attend une voiture qui n'arrive pas.
     */
    public int $stale_minutes;

    /** Les releves sont purges apres ce nombre de jours. */
    public int $retention_days;

    public static function group(): string
    {
        return 'suivi';
    }
}
