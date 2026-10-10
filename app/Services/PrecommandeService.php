<?php

namespace App\Services;

use App\Models\Product;
use App\Exceptions\PrecommandeRefusee;
use App\Models\Precommande;
use App\Models\Town;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrecommandeService
{
    public function __construct(private readonly QuotationService $quotations) {}

    /**
     * L'assistant ne collecte que la commune. Les coordonnées de livraison
     * (adresse, nom et téléphone du destinataire) restent nulles : le client
     * les saisit lui-même sur la page du lien de paiement.
     *
     * @param array<int, array{product: Product, quantity: int|float}> $lines
     *
     * @throws PrecommandeRefusee
     */
    public function creer(User $user, array $lines, Town $town): Precommande
    {
        $quotation = $this->quotations->quote($lines, $town);

        if (! $quotation->disponible) {
            throw PrecommandeRefusee::pour((string) $quotation->raison);
        }

        // Le moteur tolère l'absence de tranche pour rester fidèle au client
        // web, qui facture alors 0 de livraison. Une pré-commande créée par une
        // machine ne doit pas promettre une livraison gratuite par accident.
        if ($quotation->bracket === null) {
            // Deux causes tres differentes pour un meme bracket null : la town
            // n'est pas desservie, ou elle l'est mais le panier sort de toutes
            // les tranches. Les confondre ferait dire a un assistant qu'on ne
            // livre pas chez un client qu'on livre.
            throw PrecommandeRefusee::pour(
                in_array(QuotationService::WARNING_HORS_TRANCHE, $quotation->warnings, true)
                    ? PrecommandeRefusee::PANIER_HORS_TRANCHE
                    : PrecommandeRefusee::AUCUN_TARIF_LIVRAISON
            );
        }

        $premier = $lines[array_key_first($lines)]['product'];

        return DB::transaction(function () use ($user, $lines, $town, $quotation, $premier) {
            $precommande = Precommande::query()->create([
                'refernce' => $this->reference(),
                'user_id' => $user->id,
                'restaurant_id' => $premier->restaurant_id,
                'town_id' => $town->id,
                // Renseignées plus tard, par le client, sur la page de paiement.
                'adresse_delivery' => null,
                'street' => null,
                'number_street' => null,
                'reference_adresse' => null,
                'recipient_name' => null,
                'recipient_phone' => null,
                'sous_total' => $quotation->sous_total,
                'frais_livraison' => $quotation->frais_livraison,
                'service_price' => $quotation->service_price,
                'total' => $quotation->total,
                'currency_id' => $quotation->currency?->id,
                'delivrery_price_id' => $quotation->bracket->id,
                'expires_at' => now()->addHours((int) config('precommande.validite_heures')),
                'status' => Precommande::STATUT_EN_ATTENTE,
            ]);

            foreach ($lines as $line) {
                $precommande->products()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    // Le prix du devis, pas celui du produit au moment du paiement.
                    'price' => $line['product']->price,
                ]);
            }

            return $precommande->load(['products.product', 'currency', 'restaurant']);
        });
    }

    /**
     * Préfixe P- : commandes.refernce est un entier nu et le webhook de
     * paiement cherche dessus. Les deux espaces ne doivent pas se croiser.
     */
    private function reference(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $reference = 'P-'.Str::upper(Str::random(12));

            if (! Precommande::query()->where('refernce', $reference)->exists()) {
                return $reference;
            }
        }

        throw PrecommandeRefusee::pour(PrecommandeRefusee::REFERENCE_INDISPONIBLE);
    }
}
