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

    /**
     * La position physique du client compte-t-elle, en plus de son adresse ?
     *
     * Eteinte, seule l'adresse de livraison decide : on peut commander pour
     * sa famille a Kinshasa depuis n'importe ou. Allumee, il faut aussi se
     * trouver a portee du restaurant — « je suis au Maroc, je dois voir que
     * les restaurants proches ».
     *
     * Les deux usages sont legitimes et s'excluent : c'est a
     * l'administration de trancher, pas au code.
     */
    public bool $use_client_position;

    /**
     * La ville prime-t-elle sur la distance ?
     *
     * Allumee, deux adresses de la meme ville se livrent quelle que soit la
     * distance : Kinshasa fait plus de quarante kilometres de large, et un
     * rayon de trente y refusait des livraisons normales. Le rayon reprend la
     * main quand la ville ne tranche pas — villes differentes, ou ville
     * inconnue d'un cote.
     */
    public bool $city_first;

    public static function group(): string
    {
        return 'food';
    }
}
