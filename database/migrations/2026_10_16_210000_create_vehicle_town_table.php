<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les communes d'ou un vehicule accepte une course.
 *
 * Le proprietaire, le 16 octobre : « il faut aussi rajouter la notion de
 * commune. Ville, commune. Aucune commune selectionnee, on prend toutes les
 * communes. »
 *
 * AUCUNE LIGNE veut donc dire « toutes les communes de sa ville », et c'est le
 * defaut : le parc existant n'a rien a saisir, et on ne renseigne que la
 * restriction. Une table de liaison vide ne ferme rien — c'est la meme
 * doctrine que la ville nulle, qui ouvre au lieu de fermer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_town', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('town_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vehicle_id', 'town_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_town');
    }
};
