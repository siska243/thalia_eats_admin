<?php

namespace App\Console\Commands;

use App\Models\Commande;
use App\Services\QuotationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rejoue le calcul serveur sur les commandes deja en base et mesure l'ecart
 * avec ce qui a reellement ete facture. N'ecrit rien.
 *
 * A quoi sert le chiffre : il dit au proprietaire combien de clients auraient
 * paye autre chose si QUOTATION_AUTHORITATIVE avait ete a true, et dans quel
 * sens. C'est la seule facon de basculer la facturation sans pari.
 *
 * Les lignes sont rejouees avec le prix STOCKE dans commande_products, pas
 * avec le prix actuel du produit : sinon la hausse d'un plat depuis la
 * commande serait comptee comme un ecart de calcul, ce qu'elle n'est pas. On
 * isole ainsi exactement ce que la bascule change : les frais et le service.
 */
class SimulerQuotation extends Command
{
    protected $signature = 'quotation:simuler {--limit=0 : Ne traiter que les N commandes les plus recentes}';

    protected $description = 'Compare le calcul serveur au montant reellement facture, sans rien ecrire';

    public function handle(QuotationService $quotations): int
    {
        $query = Commande::query()->with(['product.currency', 'town'])->orderByDesc('id');

        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }

        $identiques = $moins_cher = $plus_cher = 0;
        $ecart_max_hausse = $ecart_max_baisse = 0.0;
        $hausse_sur = $baisse_sur = null;
        $ignorees = [];
        $indisponibles = [];
        $warnings = [];
        $lignes = [];

        foreach ($query->cursor() as $commande) {
            $facture = (float) $commande->global_price;

            if (! $commande->town) {
                $ignorees['commune absente'] = ($ignorees['commune absente'] ?? 0) + 1;

                continue;
            }

            $quotation_lines = [];

            foreach ($commande->product as $ligne) {
                $produit = $ligne->product;

                if (! $produit) {
                    $quotation_lines = [];
                    break;
                }

                // Prix historique : on remplace sans sauvegarder.
                $produit->price = $ligne->price;
                $quotation_lines[] = ['product' => $produit, 'quantity' => $ligne->quantity];
            }

            if ($quotation_lines === []) {
                $ignorees['aucune ligne exploitable'] = ($ignorees['aucune ligne exploitable'] ?? 0) + 1;

                continue;
            }

            try {
                $quotation = $quotations->quote($quotation_lines, $commande->town);
            } catch (Throwable $e) {
                $ignorees['erreur technique'] = ($ignorees['erreur technique'] ?? 0) + 1;

                continue;
            }

            if (! $quotation->disponible) {
                $indisponibles[$quotation->raison] = ($indisponibles[$quotation->raison] ?? 0) + 1;

                continue;
            }

            foreach ($quotation->warnings as $w) {
                $warnings[$w] = ($warnings[$w] ?? 0) + 1;
            }

            $ecart = round($quotation->total - $facture, 2);

            if (abs($ecart) <= 0.01) {
                $identiques++;
            } elseif ($ecart > 0) {
                $plus_cher++;

                if ($ecart > $ecart_max_hausse) {
                    $ecart_max_hausse = $ecart;
                    $hausse_sur = $commande->refernce;
                }
            } else {
                $moins_cher++;

                if ($ecart < $ecart_max_baisse) {
                    $ecart_max_baisse = $ecart;
                    $baisse_sur = $commande->refernce;
                }
            }

            $lignes[] = [
                $commande->refernce,
                number_format($facture, 2, '.', ''),
                number_format($quotation->total, 2, '.', ''),
                number_format($ecart, 2, '.', ''),
                implode(',', $quotation->warnings) ?: '-',
            ];
        }

        $this->table(['reference', 'facture', 'serveur', 'ecart', 'warnings'], $lignes);

        $comparees = $identiques + $plus_cher + $moins_cher;

        $this->newLine();
        $this->line("Commandes comparees      : {$comparees}");
        $this->line("  meme montant           : {$identiques}");
        $this->line("  le serveur facture PLUS: {$plus_cher} (ecart max +{$ecart_max_hausse}".($hausse_sur ? ", commande #{$hausse_sur}" : '').')');
        $this->line("  le serveur facture MOINS: {$moins_cher} (ecart max {$ecart_max_baisse}".($baisse_sur ? ", commande #{$baisse_sur}" : '').')');

        $this->newLine();
        $this->line('Non comparees :');

        foreach ($ignorees as $raison => $n) {
            $this->line("  {$raison} : {$n}");
        }

        foreach ($indisponibles as $raison => $n) {
            $this->line("  cotation indisponible ({$raison}) : {$n}");
        }

        if ($ignorees === [] && $indisponibles === []) {
            $this->line('  aucune');
        }

        $this->newLine();
        $this->line('Avertissements rencontres :');

        foreach ($warnings as $w => $n) {
            $this->line("  {$w} : {$n}");
        }

        if ($warnings === []) {
            $this->line('  aucun');
        }

        return self::SUCCESS;
    }
}
