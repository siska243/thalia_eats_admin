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

    public function __construct(public readonly string $raison)
    {
        parent::__construct($raison);
    }

    public static function pour(string $raison): self
    {
        return new self($raison);
    }
}
