<?php

namespace App\Services;

use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Town;
use Illuminate\Support\Collection;

/**
 * Reproduit à l'identique la règle de tarification qui vit aujourd'hui dans
 * le navigateur : front end/next-app/helpers/calculePrice.js, fonctions
 * price_delivrery() et total().
 *
 * Toute divergence avec ce fichier est un bug de ce service, pas du client.
 */
class QuotationService
{
    public const RAISON_PANIER_VIDE = 'panier_vide';

    public const RAISON_QUANTITE_INVALIDE = 'quantite_invalide';

    public const RAISON_MULTI_RESTAURANT = 'multi_restaurant';

    public const RAISON_DEVISES_MELANGEES = 'devises_melangees';

    public const RAISON_RESTAURANT_INATTENDU = 'restaurant_inattendu';

    public const WARNING_AUCUN_TARIF_ACTIF = 'aucun_tarif_actif';

    public const WARNING_HORS_TRANCHE = 'hors_tranche';

    public const WARNING_DEVISE_TRANCHE_DIFFERENTE = 'devise_tranche_differente';

    /** @var array<int, Collection<int, DelivreryPrice>> */
    private array $brackets_cache = [];

    /**
     * @param  array<int, array{product: Product, quantity: int|float}>  $lines  la quantité arrive
     *                                                                           du client sans validation d'entier garantie côté service : l'intégrité relève de
     *                                                                           la validation de l'appelant (le FormRequest de l'endpoint la valide déjà en integer)
     * @param  Town  $town  town de l'adresse de LIVRAISON, jamais celle du restaurant
     */
    public function quote(array $lines, Town $town, ?int $expected_restaurant_id = null): Quotation
    {
        if ($lines === []) {
            return Quotation::refus(self::RAISON_PANIER_VIDE);
        }

        $restaurant_ids = [];
        $currency_ids = [];
        $sous_total = 0.0;

        foreach ($lines as $line) {
            $product = $line['product'];
            // Le JS ne coerce jamais la quantité (`item.quantity * item.price`) :
            // garder la valeur numérique brute plutôt que de tronquer un int.
            $quantity = (float) $line['quantity'];

            if ($quantity < 1) {
                return Quotation::refus(self::RAISON_QUANTITE_INVALIDE);
            }

            $restaurant_ids[(int) $product->restaurant_id] = true;
            $currency_ids[(int) $product->currency_id] = true;

            // products.price, jamais promotionnalPrice : c'est ce que valide()
            // écrit dans commande_products.price.
            $sous_total += (float) $product->price * $quantity;
        }

        // calcul_price() applique parseFloat(sum.toFixed(2)) côté client. PHP round()
        // diverge de toFixed(2) d'un centime sur les valeurs charnières
        // (round(8.165,2)=8.17 contre 8.16 en JS) : sprintf reproduit toFixed.
        $sous_total = (float) sprintf('%.2F', $sous_total);

        if (count($restaurant_ids) > 1) {
            return Quotation::refus(self::RAISON_MULTI_RESTAURANT);
        }

        if (count($currency_ids) > 1) {
            return Quotation::refus(self::RAISON_DEVISES_MELANGEES);
        }

        if ($expected_restaurant_id !== null && ! isset($restaurant_ids[$expected_restaurant_id])) {
            return Quotation::refus(self::RAISON_RESTAURANT_INATTENDU);
        }

        $currency = $lines[array_key_first($lines)]['product']->currency;
        $warnings = [];

        $brackets = $this->bracketsFor($town);

        if ($brackets->isEmpty()) {
            // price_delivrery() renvoie null, l'appelant retombe sur 0 / 0.
            $warnings[] = self::WARNING_AUCUN_TARIF_ACTIF;

            return new Quotation(true, $sous_total, 0.0, 0.0, $sous_total, $currency, null, $warnings);
        }

        $bracket = $brackets->first(
            fn (DelivreryPrice $b) => $sous_total >= (float) $b->interval_pricing
                && $sous_total <= (float) $b->interval_max_price
        );

        if ($bracket === null) {
            // findPricing est undefined, le client applique ?? 0 sur les deux frais.
            $warnings[] = self::WARNING_HORS_TRANCHE;

            return new Quotation(true, $sous_total, 0.0, 0.0, $sous_total, $currency, null, $warnings);
        }

        // Le client ne compare jamais la devise de la tranche à celle des
        // produits : on signale sans refuser, pour rester bug-compatible.
        if ($currency !== null && (int) $bracket->currency_id !== (int) $currency->id) {
            $warnings[] = self::WARNING_DEVISE_TRANCHE_DIFFERENTE;
        }

        $frais = (float) $bracket->frais;
        $service = (float) $bracket->service_price;

        return new Quotation(
            true,
            $sous_total,
            $frais,
            $service,
            // total() côté client ne fait aucun arrondi : sous_price + service + livraison bruts.
            $sous_total + $frais + $service,
            $currency,
            $bracket,
            $warnings,
        );
    }

    /**
     * @return Collection<int, DelivreryPrice>
     */
    private function bracketsFor(Town $town): Collection
    {
        $id = (int) $town->id;

        // orderBy('id') reproduit l'ordre dans lequel DefaultDataController::index()
        // renvoie les tranches au client — ordre sur lequel s'appuie le .find()
        // de calculePrice.js. S'en remettre à l'ordre naturel de MySQL exposerait
        // à une divergence silencieuse.
        return $this->brackets_cache[$id] ??= DelivreryPrice::query()
            ->where('town_id', $id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }
}
