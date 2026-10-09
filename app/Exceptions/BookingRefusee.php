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

    public function __construct(public readonly string $raison)
    {
        parent::__construct($raison);
    }
}
