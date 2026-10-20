<?php

namespace App\Exceptions;

use Exception;

/**
 * Une reservation refusee, avec sa raison lisible par le code.
 *
 * Le motif voyage comme un code court plutot qu'un message : le client mobile
 * et le client web n'affichent pas la meme phrase, et comparer des phrases
 * casse a la premiere correction de typographie.
 */
class BookingRefusee extends Exception
{
    public const VEHICULE_INTROUVABLE = 'vehicule_introuvable';

    public const VEHICULE_INDISPONIBLE = 'vehicule_indisponible';

    public const CRENEAU_INVALIDE = 'creneau_invalide';

    /** Le vehicule n'est pas propose dans le mode de tarification demande. */
    public const MODE_INDISPONIBLE = 'mode_indisponible';

    /** La commune de prise en charge n'est pas desservie par la location. */
    public const HORS_ZONE = 'hors_zone';

    /**
     * Le vehicule n'opere pas dans la ville de prise en charge demandee.
     *
     * Distinct de `HORS_ZONE`, et ce n'est pas une nuance : « cette commune
     * n'est pas desservie » envoie le client vers WhatsApp, « ce vehicule
     * n'est pas d'ici » l'envoie vers un autre vehicule. Deux suites
     * differentes pour le client, donc deux codes.
     */
    public const HORS_VILLE = 'hors_ville';

    /**
     * Le type de course impose un mode de tarification, et ce n'est pas celui
     * qui a ete demande.
     *
     * On REFUSE plutot que de basculer en silence : le client a choisi un
     * mode et un prix, et un autre prix n'est pas le sien.
     */
    /**
     * Le vehicule n'accepte pas de course au depart de cette commune.
     *
     * Encore un refus distinct : « cette commune n'est pas desservie du tout »
     * (HORS_ZONE), « ce vehicule n'est pas de cette ville » (HORS_VILLE) et
     * « ce vehicule ne sort pas de ces communes-la » n'appellent pas la meme
     * suite. Le dernier se resout en choisissant une autre voiture de la meme
     * ville, et le message nomme donc les communes servies.
     */
    public const HORS_COMMUNE = 'hors_commune';

    public const MODE_IMPOSE = 'mode_impose';

    /**
     * Le vehicule propose des types de course, et aucun n'a ete choisi.
     *
     * Le type decide du tarif applique, de la duree minimale et parfois du
     * mode : le laisser vide revient a facturer une course au hasard de ce
     * qui restait par defaut. L'ecran ne laisse plus passer l'etape, et le
     * serveur le redit pour les clients qui ne l'ont pas encore.
     *
     * Exige SEULEMENT quand le vehicule en propose : un vehicule sans type
     * reste reservable comme avant, sinon les applications deja installees
     * cesseraient de pouvoir reserver quoi que ce soit.
     */
    public const TYPE_REQUIS = 'type_requis';

    /** Le creneau est plus court que la duree minimale propre a ce type. */
    public const DUREE_MINIMALE_TYPE = 'duree_minimale_type';

    /** La duree demandee sort des bornes du mode de tarification. */
    public const DUREE_HORS_BORNES = 'duree_hors_bornes';

    /**
     * Le depart demande est passe, ou trop proche.
     *
     * Une course ne se prend pas pour tout de suite : il faut joindre un
     * chauffeur. Le delai vit dans les reglages, et le message qui accompagne
     * ce code le nomme.
     */
    public const DELAI_PREVENANCE = 'delai_prevenance';

    /**
     * Le client a deja une reservation non reglee, encore valide.
     *
     * Une seule course en cours a la fois : il paie celle-la ou il l'annule.
     * Le message qui accompagne ce code nomme la reservation concernee, sinon
     * le client cherche laquelle le bloque dans une liste.
     */
    public const RESERVATION_NON_REGLEE = 'reservation_non_reglee';

    /**
     * Un autre client est deja en train de payer ce vehicule sur ce creneau.
     *
     * Le refus est immediat, pas une attente : le second client comprend ce
     * qui se passe et choisit — patienter le temps de l'echeance, ou prendre
     * un autre vehicule. Le faire patienter sans rien dire sur une page de
     * paiement aurait ete pire que le refus.
     *
     * Distinct de `vehicule_indisponible`, qui dit « c'est vendu » : ici le
     * creneau peut tres bien se liberer dans le quart d'heure.
     */
    public const PAIEMENT_EN_COURS = 'paiement_en_cours';

    /**
     * @param  string|null  $messageClient  La phrase a montrer, quand elle
     *                                      depend des reglages et ne peut
     *                                      donc pas etre figee cote
     *                                      controleur.
     */
    public function __construct(
        public readonly string $raison,
        public readonly ?string $messageClient = null,
    ) {
        parent::__construct($raison);
    }
}
