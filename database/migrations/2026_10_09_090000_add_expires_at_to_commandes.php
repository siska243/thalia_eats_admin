<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'echeance de paiement d'une commande.
 *
 * `created_at` ne pouvait pas servir : une commande nait comme panier au
 * statut 1 et peut rester en composition plusieurs dizaines de minutes avant
 * de partir au paiement. Mesurer le delai depuis la creation aurait fait
 * naitre deja perimee la commande envoyee au paiement par un client qui prend
 * le temps de choisir — il aurait ete debite pour une commande annulee.
 *
 * La colonne porte donc une date, repoussee a chaque enregistrement tant que
 * la commande n'est pas reglee. C'est le meme champ, et la meme regle, que
 * `bookings.expires_at` cote location.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            // Nullable : les lignes existantes n'en portent pas, et « pas
            // d'echeance » doit se lire comme « ne jamais expirer ». Les faire
            // expirer d'office ferait disparaitre des commandes vivantes.
            $table->timestamp('expires_at')->nullable()->after('cancel_at');
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
