<?php

namespace App\Console\Commands;

use App\Models\Commande;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Annule les commandes restees en cours trop longtemps.
 *
 * Une commande qui n'avance plus ne disparait pas d'elle-meme : elle reste
 * affichee au client dans « En cours », occupe le tableau de bord du
 * restaurateur, et surtout l'empeche d'en passer une nouvelle — la creation
 * refuse tant qu'une commande n'est pas reglee. Le client se retrouve bloque
 * par un repas qu'il n'aura jamais.
 *
 * Le delai vit dans le modele (`Commande::JOURS_AVANT_ABANDON`), pas ici :
 * c'est une regle du domaine, pas un detail de cette commande console.
 *
 * L'annulation passe par `save()` et non par une mise a jour en masse, pour
 * que le crochet `saving` du modele pose `cancel_at` en meme temps que le
 * statut. Un `update()` global ecrirait le statut seul et recreerait
 * exactement l'incoherence que ce crochet existe pour empecher.
 */
class AnnulerCommandesAbandonnees extends Command
{
    protected $signature = 'commandes:annuler-abandonnees
                            {--simuler : Affiche ce qui serait annule sans rien ecrire}';

    protected $description = 'Annule les commandes en cours depuis plus de deux jours';

    public function handle(): int
    {
        $simulation = (bool) $this->option('simuler');
        $jours = Commande::JOURS_AVANT_ABANDON;

        $total = Commande::query()->abandonnee()->count();

        if ($total === 0) {
            $this->info("Aucune commande en cours depuis plus de {$jours} jours.");

            return self::SUCCESS;
        }

        $this->info(
            ($simulation ? '[simulation] ' : '')
            . "{$total} commande(s) en cours depuis plus de {$jours} jours."
        );

        $annulees = 0;

        // Par lots : la table grandit, et la charger entierement en memoire
        // finirait par echouer sans prevenir.
        Commande::query()->abandonnee()->chunkById(100, function ($commandes) use ($simulation, &$annulees) {
            foreach ($commandes as $commande) {
                $this->line(sprintf(
                    '  #%s  creee le %s  statut %s',
                    $commande->refernce,
                    $commande->created_at?->format('d/m/Y H:i'),
                    $commande->status_id
                ));

                if ($simulation) {
                    continue;
                }

                $commande->status_id = Commande::STATUT_ANNULEE;
                $commande->save();

                // Aucune table n'historise les changements de statut : le
                // journal est la seule trace de qui a annule quoi, et pourquoi.
                Log::info('Commande annulee automatiquement', [
                    'commande_id' => $commande->id,
                    'reference' => $commande->refernce,
                    'creee_le' => $commande->created_at?->toDateTimeString(),
                    'motif' => 'en cours depuis plus de ' . Commande::JOURS_AVANT_ABANDON . ' jours',
                ]);

                $annulees++;
            }
        });

        if ($simulation) {
            $this->comment('Simulation : rien n\'a ete ecrit.');

            return self::SUCCESS;
        }

        $this->info("{$annulees} commande(s) annulee(s).");

        return self::SUCCESS;
    }
}
