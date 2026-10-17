<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La trace d'un envoi deja fait.
 *
 * Deux chemins marquent une commande payee : le webhook de la passerelle et
 * la verification manuelle depuis l'application. Le webhook peut etre rejoue
 * — son propre commentaire le dit — et rien n'empecherait alors d'envoyer
 * trois fois le meme courriel au client.
 *
 * Meme motif que `stock_decremente_at`, qui protege deja le stock du meme
 * rejeu : une colonne, posee une fois, qui dit que c'est fait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->timestamp('notifiee_at')->nullable()->after('paied_at');
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn('notifiee_at');
        });
    }
};
