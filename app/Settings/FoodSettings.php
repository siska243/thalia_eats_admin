<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;
use Throwable;

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

    /**
     * Le suivi de stock est-il actif ?
     *
     * Le suivi se decide plat par plat, et c'est cette decision qui commande.
     * Celui-ci ne sert qu'a tout eteindre d'un coup si la fonctionnalite se
     * comporte mal en production, sans repasser sur chaque plat.
     *
     * Allume par defaut, contrairement aux autres reglages de ce groupe :
     * eteint, il annulerait la decision que l'administration vient de prendre.
     */
    public bool $stock_actif;

    /**
     * Le montant minimum d'un panier.
     *
     * Zero signifie « aucune limite », et c'est la valeur de depart : ce
     * reglage arrive sur une application en production, ou poser un seuil
     * refuserait des paniers aujourd'hui acceptes sans que personne ne l'ait
     * decide.
     *
     * Exprime dans la devise de la commande, celle des plats commandes. La
     * plateforme n'a qu'une devise active a la fois.
     *
     * Il porte sur le SOUS-TOTAL des plats, pas sur le total paye : compter
     * la livraison et le service ferait passer la barre a un panier qui ne
     * l'atteint pas, et le seuil ne voudrait plus dire ce qu'il annonce.
     */
    public float $montant_minimum_commande;

    public static function group(): string
    {
        return 'food';
    }

    /**
     * Le suivi de stock est-il actif ? Lu une seule fois pour tout le code.
     *
     * Repli sur VRAI quand la table des reglages ne repond pas — deploiement
     * en cours, migration pas encore passee. Meme raisonnement que
     * `ExpirationSettings::delai()`, et meme lecon que `/api/default` qui
     * tombait en 500 des que `settings` etait vide : un reglage absent ne doit
     * pas casser un chemin de commande.
     *
     * VRAI et non faux, parce que c'est l'etat normal : l'interrupteur ne sert
     * qu'a eteindre en cas de probleme. Et repondre vrai n'elargit rien,
     * aucun plat n'etant suivi tant qu'on ne l'a pas dit.
     */
    public static function stockActif(): bool
    {
        try {
            return app(self::class)->stock_actif;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Le montant minimum, et zero quand les reglages ne repondent pas.
     *
     * Meme repli que `stockActif()` au-dessus, et meme raison : un reglage
     * absent — deploiement en cours, migration pas encore passee — ne doit
     * pas casser un chemin de commande. Zero ne refuse personne, c'est le
     * repli sur lequel on peut se tromper sans consequence.
     */
    public static function montantMinimum(): float
    {
        try {
            return (float) app(self::class)->montant_minimum_commande;
        } catch (Throwable) {
            return 0.0;
        }
    }
}
