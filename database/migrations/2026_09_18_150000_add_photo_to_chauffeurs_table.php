<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La photo du chauffeur.
     *
     * Elle est montree au client avant la course : savoir qui vient le
     * chercher, et reconnaitre la personne a l'arrivee. C'est aussi ce qui
     * rend credible le code de prise en charge — on verifie qu'on monte avec
     * la bonne personne avant de lui donner le code.
     *
     * Migration separee plutot que modification de la creation : la table
     * existe deja en base, et une migration additive se rejoue partout sans
     * perte.
     */
    public function up(): void
    {
        Schema::table('chauffeurs', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('chauffeurs', function (Blueprint $table) {
            $table->dropColumn('photo');
        });
    }
};
