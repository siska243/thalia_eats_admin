<?php

namespace App\Services;

use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Precommande;
use Illuminate\Support\Facades\DB;

/**
 * Transforme une pré-commande payée en Commande ordinaire.
 *
 * La Commande produite doit être indiscernable d'une commande payée par le
 * chemin habituel : statut 2, paied_at renseigné, mêmes lignes. Restaurant et
 * livreur ne doivent voir aucune différence.
 */
class ConversionPrecommande
{
    public function convertirSiPossible(string $reference): ?Commande
    {
        $precommande = Precommande::query()
            ->with('products')
            ->where('refernce', $reference)
            ->where('status', Precommande::STATUT_EN_ATTENTE)
            ->first();

        // Ni une pré-commande, ni une pré-commande encore convertible.
        // L'expiration n'est PAS un motif de refus ici : le client a pu payer
        // juste avant, et refuser encaisserait sans livrer.
        return $precommande ? $this->convertir($precommande) : null;
    }

    public function convertir(Precommande $precommande): Commande
    {
        return DB::transaction(function () use ($precommande) {
            $derniere = Commande::query()->orderByDesc('id')->first();

            $commande = new Commande;
            // Entier nu, comme toutes les references de commande : le webhook
            // cherche dessus, et les deux espaces restent disjoints.
            $commande->refernce = (string) ($derniere ? 1000 + $derniere->id : 1000);
            $commande->user_id = $precommande->user_id;
            $commande->status_id = 2;
            $commande->town_id = $precommande->town_id;
            $commande->adresse_delivery = $precommande->adresse_delivery;
            $commande->street = $precommande->street;
            $commande->number_street = $precommande->number_street;
            $commande->reference_adresse = $precommande->reference_adresse;
            $commande->lat = $precommande->lat;
            $commande->long = $precommande->long;
            $commande->recipient_name = $precommande->recipient_name;
            $commande->recipient_phone = $precommande->recipient_phone;
            $commande->global_price = $precommande->total;
            $commande->price_delivery = $precommande->frais_livraison;
            $commande->price_service = $precommande->service_price;
            $commande->reference_paiement = $precommande->reference_paiement;
            $commande->code_confirmation = rand(1000, 9999);
            $commande->code_confirmation_restaurant = rand(1000, 9999);
            $commande->paied_at = now()->format('Y-m-d H:i:s');
            $commande->save();

            // Un meme uid poste deux fois cree deux lignes de precommande pour
            // un seul produit : les totaux restent justes, mais le restaurant
            // verrait le meme plat deux fois sur son bon. On regroupe ici, au
            // moment ou la commande devient reelle.
            $regroupees = $precommande->products
                ->groupBy('product_id')
                ->map(fn ($lignes) => [
                    'product_id' => $lignes->first()->product_id,
                    'quantity' => $lignes->sum('quantity'),
                    // Toutes les lignes d'un meme produit portent le prix du
                    // meme devis : prendre la premiere est sans ambiguite.
                    'price' => $lignes->first()->price,
                ]);

            foreach ($regroupees as $ligne) {
                $commande_product = new CommandeProduct;
                $commande_product->commande_id = $commande->id;
                $commande_product->product_id = $ligne['product_id'];
                $commande_product->user_id = $precommande->user_id;
                $commande_product->quantity = $ligne['quantity'];
                // Le prix du devis, pas celui du produit aujourd'hui.
                $commande_product->price = $ligne['price'];
                $commande_product->currency_id = $precommande->currency_id;
                $commande_product->save();
            }

            $precommande->status = Precommande::STATUT_PAYEE;
            $precommande->commande_id = $commande->id;
            $precommande->paied_at = now();
            $precommande->save();

            return $commande;
        });
    }
}
