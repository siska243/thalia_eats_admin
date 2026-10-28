<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Le marqueur « ce compte a ete supprime par son titulaire ».
 *
 * Il ne se confond ni avec `blocked_at`, qui dit qu'un administrateur a mis
 * quelqu'un dehors, ni avec `deleted_at`, colonne inutilisee heritee d'une
 * vieille migration : `deleted_at` est le nom que `SoftDeletes` reconnait, et
 * le jour ou le trait serait ajoute au modele, tous les comptes supprimes
 * disparaitraient des requetes — avec leurs commandes, c'est-a-dire l'inverse
 * de ce qui est demande ici.
 *
 * Une suppression pose les deux : `account_deleted_at` dit pourquoi, et
 * `blocked_at` ferme la porte, par le chemin qui existe deja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('account_deleted_at')->nullable()->after('blocked_reason');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('account_deleted_at');
        });
    }
};
