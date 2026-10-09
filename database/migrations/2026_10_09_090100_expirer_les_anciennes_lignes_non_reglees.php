<?php

use App\Models\Booking;
use App\Models\Commande;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les lignes heritees, non reglees depuis plus que le delai, sont expirees.
 *
 * La regle « une seule course et une seule commande en cours a la fois » entre
 * en vigueur avec un passif : des reservations jamais payees et des commandes
 * restees en attente depuis des mois. Sans ce passage, leurs clients seraient
 * bloques des la mise en production, par des lignes qu'ils ne peuvent meme
 * plus retrouver.
 *
 * Deux garanties :
 *
 * 1. Le compte est affiche AVANT la moindre ecriture. Le proprietaire doit
 *    voir l'ampleur sur sa base de production avant de laisser le deploiement
 *    continuer, pas la decouvrir apres coup.
 *
 * 2. `down()` remet exactement l'etat anterieur. L'ancien statut de chaque
 *    ligne est recopie dans une table de trace avant d'etre ecrase : sans
 *    elle, un retour arriere ne saurait pas distinguer une commande qui etait
 *    en attente (1) d'une qui attendait son paiement (5), et rendrait a tout
 *    le monde le meme statut.
 */
return new class extends Migration
{
    /** La table de trace, le temps que la migration soit reversible. */
    private const TRACE = 'expirations_retrogradables';

    public function up(): void
    {
        $limite = now()->subMinutes((int) config('expiration.minutes'));

        if (! Schema::hasTable(self::TRACE)) {
            Schema::create(self::TRACE, function (Blueprint $table) {
                $table->id();
                $table->string('cible', 20);
                $table->unsignedBigInteger('cible_id');
                $table->string('statut_precedent', 50)->nullable();
                $table->timestamp('cancel_at_precedent')->nullable();
            });
        }

        $reservations = $this->reservationsAExpirer($limite)->get(['id', 'status']);
        $commandes = $this->commandesAExpirer($limite)->get(['id', 'status_id', 'cancel_at']);

        // Le compte, avant d'ecrire quoi que ce soit.
        $this->dire(sprintf(
            'Expiration des lignes non reglees depuis plus de %d minutes (avant le %s) :',
            (int) config('expiration.minutes'),
            $limite->format('d/m/Y H:i'),
        ));
        $this->dire(sprintf('  reservations a expirer : %d', $reservations->count()));
        $this->dire(sprintf('  commandes a annuler    : %d', $commandes->count()));

        if ($reservations->isEmpty() && $commandes->isEmpty()) {
            return;
        }

        foreach ($reservations as $reservation) {
            DB::table(self::TRACE)->insert([
                'cible' => 'booking',
                'cible_id' => $reservation->id,
                'statut_precedent' => $reservation->status,
                'cancel_at_precedent' => null,
            ]);
        }

        foreach ($commandes as $commande) {
            DB::table(self::TRACE)->insert([
                'cible' => 'commande',
                'cible_id' => $commande->id,
                'statut_precedent' => (string) $commande->status_id,
                'cancel_at_precedent' => $commande->cancel_at,
            ]);
        }

        // Mise a jour en masse, volontairement hors Eloquent : l'etat voulu est
        // ecrit explicitement, champ par champ, et aucun crochet de modele ne
        // vient repousser l'echeance des lignes qu'on est en train d'eteindre.
        $this->reservationsAExpirer($limite)->update([
            'status' => Booking::STATUS_EXPIRED,
            'updated_at' => now(),
        ]);

        // Le statut ET la date d'annulation, ensemble : c'est l'invariant que
        // le modele tient a l'ecriture, et le contourner recreerait exactement
        // les lignes incoherentes heritees.
        $this->commandesAExpirer($limite)->update([
            'status_id' => Commande::STATUT_ANNULEE,
            'cancel_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TRACE)) {
            return;
        }

        foreach (DB::table(self::TRACE)->where('cible', 'booking')->get() as $trace) {
            DB::table('bookings')->where('id', $trace->cible_id)->update([
                'status' => $trace->statut_precedent,
            ]);
        }

        foreach (DB::table(self::TRACE)->where('cible', 'commande')->get() as $trace) {
            DB::table('commandes')->where('id', $trace->cible_id)->update([
                'status_id' => (int) $trace->statut_precedent,
                'cancel_at' => $trace->cancel_at_precedent,
            ]);
        }

        Schema::dropIfExists(self::TRACE);
    }

    /**
     * Les reservations jamais reglees, assez anciennes.
     *
     * Seul `pending_payment` est touche : une reservation confirmee, en
     * course, terminee, annulee ou perdue a deja ete tranchee, et la reecrire
     * effacerait une decision prise par quelqu'un.
     */
    private function reservationsAExpirer(Carbon $limite): Builder
    {
        return DB::table('bookings')
            ->where('status', Booking::STATUS_PENDING_PAYMENT)
            ->where('created_at', '<', $limite);
    }

    /**
     * Les commandes non reglees, assez anciennes.
     *
     * Seuls les statuts 1 et 5 sont touches : « En cours » (2) est une
     * commande que le restaurant prepare, « Livrer » (3) et « Annuler » (4)
     * sont finies. `delivery_at` est verifie en plus, parce que statut et
     * dates ont diverge sur des lignes anciennes et qu'une commande
     * reellement livree ne doit pas etre annulee pour autant.
     */
    private function commandesAExpirer(Carbon $limite): Builder
    {
        return DB::table('commandes')
            ->whereIn('status_id', [Commande::STATUT_EN_ATTENTE, Commande::STATUT_ATTENTE_PAIEMENT])
            ->whereNull('cancel_at')
            ->whereNull('delivery_at')
            ->where('created_at', '<', $limite);
    }

    private function dire(string $ligne): void
    {
        // `echo` et non un logger : cette sortie doit apparaitre dans le
        // terminal de `php artisan migrate`, sous les yeux de qui deploie.
        // Muette sous la suite de tests, ou la base est refaite a chaque
        // classe et ou ces lignes noieraient le resultat.
        if (app()->runningUnitTests()) {
            return;
        }

        echo $ligne.PHP_EOL;
    }
};
