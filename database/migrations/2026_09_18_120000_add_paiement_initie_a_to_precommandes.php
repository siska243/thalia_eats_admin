<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horodater le départ d'une sollicitation de paiement.
 *
 * Une pré-commande ne se ferme qu'à la réception du webhook. Entre l'appel à la
 * passerelle et cette confirmation, rien n'empêchait un second POST de rappeler
 * FlexPay : le téléphone du client sonnait deux fois pour la même commande.
 *
 * Le garde s'appuyait d'abord sur `reference_paiement` et `updated_at`. Deux
 * défauts : `reference_paiement` vient de `orderNumber`, un champ que la
 * passerelle peut ne pas fournir — une initiation réussie sans lui ne laissait
 * alors aucune trace et le double appel redevenait possible, précisément ce que
 * le garde existe pour empêcher ; et `updated_at` bouge à la moindre
 * sauvegarde, il ne dit pas « une sollicitation est partie ».
 *
 * Cette colonne ne dépend que de nous : on l'écrit quand l'appel part et qu'il
 * est accepté. Elle est nullable, aucune donnée existante n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('precommandes', function (Blueprint $table) {
            $table->timestamp('paiement_initie_a')->nullable()->after('reference_paiement');
        });
    }

    public function down(): void
    {
        Schema::table('precommandes', function (Blueprint $table) {
            $table->dropColumn('paiement_initie_a');
        });
    }
};
