<?php

use App\Models\Currency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le montant minimum d'un paiement, PAR DEVISE.
     *
     * La regle existait, ecrite en dur dans `CommandeController::valide()` :
     * « $total_price <= 2 && $method == 'cart' ». Trois defauts, corriges
     * ensemble : le seuil n'etait pas reglable, il ne valait que pour la
     * carte, et il comparait un nombre a « 2 » sans savoir dans quelle
     * devise ce nombre etait exprime.
     *
     * C'est la devise qui porte le seuil, et non un reglage global : un
     * montant n'a pas de sens sans son unite, et la plateforme n'a aucun
     * taux de change. Deux dollars et deux francs congolais ne sont pas la
     * meme barre.
     *
     * Zero par defaut, donc aucun seuil : les devises existantes ne
     * changent pas de comportement tant que l'administration n'a rien
     * saisi. L'exception est le dollar, qui reprend la valeur demandee par
     * le proprietaire.
     */
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->decimal('montant_minimum_paiement', 10, 2)->default(0);
        });

        // Idempotent : `script-run.sh` rejoue les migrations en production.
        Currency::query()
            ->whereRaw('UPPER(code) = ?', ['USD'])
            ->update(['montant_minimum_paiement' => 5]);
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('montant_minimum_paiement');
        });
    }
};
