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
