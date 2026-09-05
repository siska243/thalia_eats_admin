<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\DelivreryPrice;

/**
 * Résultat immuable d'un chiffrage. Aucun accès base, aucune logique métier :
 * il ne fait que porter le détail du calcul et sa justification.
 */
class Quotation
{
    /**
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly bool $disponible,
        public readonly float $sous_total,
        public readonly float $frais_livraison,
        public readonly float $service_price,
        public readonly float $total,
        public readonly ?Currency $currency = null,
        public readonly ?DelivreryPrice $bracket = null,
        public readonly array $warnings = [],
        public readonly ?string $raison = null,
    ) {}

    public static function refus(string $raison): self
    {
        return new self(false, 0.0, 0.0, 0.0, 0.0, null, null, [], $raison);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'disponible' => $this->disponible,
            'sous_total' => $this->sous_total,
            'frais_livraison' => $this->frais_livraison,
            'service_price' => $this->service_price,
            'total' => $this->total,
            'currency' => $this->currency ? [
                'id' => $this->currency->id,
                'code' => $this->currency->code,
                'slug' => $this->currency->slug,
            ] : null,
            'bracket_id' => $this->bracket?->id,
            'warnings' => $this->warnings,
            'raison' => $this->raison,
        ];
    }
}
