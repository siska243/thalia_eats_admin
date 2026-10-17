<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ville d'origine d'un vehicule.
 *
 * Le proprietaire, le 15 octobre : « il y a pas des limites des kilometres
 * mais il y'a des limites par rapport a la ville (province) ».
 *
 * NULLABLE pour le parc existant, qui n'en a pas : un vehicule sans ville
 * reste reservable partout, comme aujourd'hui. Aucune regression le jour de la
 * migration, et c'est la saisie en administration qui rattrape le parc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('currency_id')
                ->constrained('cities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
        });
    }
};
