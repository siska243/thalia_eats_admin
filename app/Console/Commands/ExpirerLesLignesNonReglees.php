<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Commande;
use App\Settings\ExpirationSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ecrit en base l'expiration des reservations et des commandes non reglees.
 *
 * La regle est deja juste sans cette commande : `nonReglee()` et
 * `statutEffectif()` ecartent les lignes perimees a la lecture, et le client
 * n'est jamais bloque par une ligne morte meme si le planificateur s'arrete.
 * Ce qu'elle apporte, c'est que la base finisse par dire la meme chose que
 * les ecrans : une commande perimee affichee « en attente » dans
 * l'administration, ou comptee dans un tableau de bord, ment au personnel.
 *
 * Le delai vit dans `config/expiration.php`, pas ici : c'est une regle du
 * domaine, pas un detail de cette commande console.
 */
class ExpirerLesLignesNonReglees extends Command
{
    protected $signature = 'expirations:traiter
                            {--simuler : Affiche ce qui serait expire sans rien ecrire}';

    protected $description = 'Expire les reservations et les commandes non reglees au-dela du delai';

    public function handle(): int
    {
        $simulation = (bool) $this->option('simuler');
        $minutes = ExpirationSettings::delai();

        $reservations = Booking::query()->expirable()->get();
        $commandes = Commande::query()->expiree()->get();

        $this->info(sprintf(
            '%sDelai de %d minutes : %d reservation(s) et %d commande(s) a expirer.',
            $simulation ? '[simulation] ' : '',
            $minutes,
            $reservations->count(),
            $commandes->count(),
        ));

        foreach ($reservations as $reservation) {
            $this->line(sprintf('  reservation %s  echeance %s', $reservation->reference, $reservation->expires_at?->format('d/m/Y H:i')));

            if ($simulation) {
                continue;
            }

            $reservation->expirerSiNecessaire();

            // Aucune table n'historise les changements de statut : le journal
            // est la seule trace de ce qui a ete eteint automatiquement.
            Log::info('Reservation expiree automatiquement', [
                'booking_id' => $reservation->id,
                'reference' => $reservation->reference,
                'echeance' => $reservation->expires_at?->toDateTimeString(),
            ]);
        }

        foreach ($commandes as $commande) {
            $this->line(sprintf('  commande #%s  echeance %s', $commande->refernce, $commande->expires_at?->format('d/m/Y H:i')));

            if ($simulation) {
                continue;
            }

            // Par le modele, pas par un update() en masse : le crochet pose
            // `cancel_at` en meme temps que le statut.
            $commande->status_id = Commande::STATUT_ANNULEE;
            $commande->save();

            Log::info('Commande annulee faute de paiement', [
                'commande_id' => $commande->id,
                'reference' => $commande->refernce,
                'echeance' => $commande->expires_at?->toDateTimeString(),
            ]);
        }

        if ($simulation) {
            $this->comment("Simulation : rien n'a ete ecrit.");
        }

        return self::SUCCESS;
    }
}
