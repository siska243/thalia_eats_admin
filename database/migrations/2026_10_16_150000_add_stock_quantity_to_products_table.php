<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La quantite en stock d'un plat.
 *
 * NULLABLE, et c'est tout l'interet : `null` veut dire « stock non suivi »,
 * `0` veut dire « epuise ». Sans cette distinction, le deploiement mettrait la
 * colonne a zero et tout le catalogue de production passerait epuise d'un coup.
 *
 * Le proprietaire, le 16 octobre : « le stock est pris en charge que si on
 * l'active depuis l'admin dans chaque produit ». Un plat non active garde donc
 * exactement le comportement d'aujourd'hui.
 *
 * Un booleen « suivre le stock » en plus serait un second etat a tenir
 * coherent avec le premier : une colonne suffit a dire les trois cas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('stock_quantity')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock_quantity');
        });
    }
};
