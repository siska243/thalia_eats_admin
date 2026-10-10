<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;
use Throwable;

/**
 * Le delai avant qu'une ligne non reglee expire.
 *
 * Il vaut pour les deux modules — une commande de repas comme une reservation
 * de vehicule — et c'est pour cela qu'il ne vit ni dans `RentalSettings` ni
 * dans une constante de modele : une seconde copie divergerait au premier
 * ajustement commercial, et les deux moities du produit ne repondraient plus
 * la meme chose.
 *
 * Il etait dans `config/expiration.php`, donc modifiable seulement en
 * editant un fichier et en redeployant. Le bon delai est commercial : il
 * s'ajuste en regardant les abandons reels, depuis l'administration.
 */
class ExpirationSettings extends Settings
{
    /** Minutes accordees au client pour regler, avant expiration. */
    public int $minutes;

    public static function group(): string
    {
        return 'expiration';
    }

    /**
     * Le delai effectif, lu une seule fois pour tout le code metier.
     *
     * `config/expiration.php` reste, mais uniquement comme repli : si la
     * table des reglages n'a pas encore sa ligne — deploiement en cours,
     * migration pas passee — rien ne doit tomber. Hors de ce repli, plus
     * personne ne lit la configuration : deux sources qui decident de la
     * meme chose, c'est le motif qui a deja produit deux bugs ici.
     */
    public static function delai(): int
    {
        try {
            return app(self::class)->minutes;
        } catch (Throwable) {
            return (int) config('expiration.minutes');
        }
    }
}
