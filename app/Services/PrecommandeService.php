<?php

namespace App\Services;

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
     * @param  array<int, array{product: \App\Models\Product, quantity: int|float}>  $lines
     * @param  array{adresse: string, street: ?string, number_street: ?string, reference: ?string}  $adresse
     * @param  array{name: string, phone: string}  $destinataire
     *
     * @throws PrecommandeRefusee
     */
    public function creer(User $user, array $lines, Town $town, array $adresse, array $destinataire): Precommande
    {
        $quotation = $this->quotations->quote($lines, $town);

        if (! $quotation->disponible) {
            throw PrecommandeRefusee::pour((string) $quotation->raison);
        }

        // Le moteur tolère l'absence de tranche pour rester fidèle au client
        // web, qui facture alors 0 de livraison. Une pré-commande créée par une
        // machine ne doit pas promettre une livraison gratuite par accident.
        if ($quotation->bracket === null) {
            throw PrecommandeRefusee::pour(PrecommandeRefusee::AUCUN_TARIF_LIVRAISON);
        }

        $premier = $lines[array_key_first($lines)]['product'];

        return DB::transaction(function () use ($user, $lines, $town, $adresse, $destinataire, $quotation, $premier) {
            $precommande = Precommande::query()->create([
                'refernce' => $this->reference(),
                'user_id' => $user->id,
                'restaurant_id' => $premier->restaurant_id,
                'town_id' => $town->id,
                'adresse_delivery' => $adresse['adresse'],
                'street' => $adresse['street'] ?? null,
                'number_street' => $adresse['number_street'] ?? null,
                'reference_adresse' => $adresse['reference'] ?? null,
                'recipient_name' => $destinataire['name'],
                'recipient_phone' => $destinataire['phone'],
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

            return $precommande->load('products');
        });
    }

    /**
     * Préfixe P- : commandes.refernce est un entier nu et le webhook de
     * paiement cherche dessus. Les deux espaces ne doivent pas se croiser.
     */
    private function reference(): string
    {
        do {
            $reference = 'P-'.Str::upper(Str::random(12));
        } while (Precommande::query()->where('refernce', $reference)->exists());

        return $reference;
    }
}
