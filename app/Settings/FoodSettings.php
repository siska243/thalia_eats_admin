<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Les reglages de la restauration.
 *
 * Pendant de `RentalSettings` pour la location. Ces valeurs apparaissent dans
 * le catalogue, dans le devis et dans le refus montre au client : une seconde
 * copie divergerait a la premiere negociation commerciale.
 */
class FoodSettings extends Settings
{
    /**
     * Le rayon maximal entre un restaurant et l'adresse de livraison.
     *
     * Une ville peut le surcharger (`cities.max_delivery_radius_km`). Sans
     * surcharge, c'est cette valeur qui s'applique.
     */
    public float $max_delivery_radius_km;

    /**
     * La phrase montree quand le restaurant est trop loin.
     *
     * Trois emplacements nommes : `:restaurant`, `:distance`, `:rayon`. Elle
     * vit ici et non dans le code, comme `RentalSettings::out_of_zone_message`,
     * pour se corriger sans livraison.
     */
    public string $out_of_radius_message;

    public static function group(): string
    {
        return 'food';
    }
}
