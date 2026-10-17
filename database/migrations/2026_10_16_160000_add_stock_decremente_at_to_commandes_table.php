<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La trace du retrait de stock d'une commande.
 *
 * Elle sert a deux choses, et la seconde est la raison d'etre de la colonne.
 *
 * 1. Tracer : « toute action qui modifie une commande doit etre confirmable et
 *    tracable », et retirer du stock en est une.
 * 2. Garantir qu'on ne retire QU'UNE FOIS. Une commande devient payee a trois
 *    endroits, et le webhook de la passerelle peut arriver deux fois pour une
 *    seule transaction : sans marque en base, le second passage retirerait le
 *    stock une seconde fois. `paied_at` ne suffit pas, l'un des trois chemins
 *    l'ecrit sans condition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->timestamp('stock_decremente_at')->nullable()->after('paied_at');
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn('stock_decremente_at');
        });
    }
};
