<?php

namespace App\Exceptions;

use Exception;

/**
 * Refus métier de créer une pré-commande. La raison est destinée à un agent :
 * elle doit être stable et exploitable, pas jolie.
 */
class PrecommandeRefusee extends Exception
{
    public const AUCUN_TARIF_LIVRAISON = 'aucun_tarif_livraison';

    public const PANIER_HORS_TRANCHE = 'panier_hors_tranche';

    public const REFERENCE_INDISPONIBLE = 'reference_indisponible';

    public const TROP_DE_PRECOMMANDES = 'trop_de_precommandes';

    public function __construct(
        public readonly string $raison,
        /**
         * La phrase à montrer, quand le refus dépend d'un réglage et ne peut
         * donc pas être figée côté contrôleur. Même principe que
         * `BookingRefusee::$messageClient`.
         */
        public readonly ?string $messageClient = null,
    ) {
        parent::__construct($raison);
    }

    public static function pour(string $raison, ?string $messageClient = null): self
    {
        return new self($raison, $messageClient);
    }
}
