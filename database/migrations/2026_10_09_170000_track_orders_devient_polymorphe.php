<?php

use App\Models\Commande;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une seule table de releves, pour les repas comme pour la location.
 *
 * `track_orders` tenait deja la trace des livraisons. Lui ajouter une table
 * jumelle pour les courses de location aurait fait deux ecritures pour la
 * meme donnee — le motif qui a deja produit deux bugs de production ici. Elle
 * devient donc polymorphe, et les lignes existantes sont rattachees a leur
 * commande.
 *
 * `commande_id` reste : la reponse de `GET /api/user/commande/get-track/{uid}`
 * la contient, et l'application mobile deja installee la lit. Elle n'est plus
 * ecrite a la main nulle part : le modele la deduit du lien polymorphe, pour
 * qu'il n'y ait qu'un seul endroit qui decide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('track_orders', function (Blueprint $table) {
            $table->nullableMorphs('suivable');
        });

        // Les releves deja en base appartiennent tous a une commande.
        DB::table('track_orders')->update([
            'suivable_type' => Commande::class,
            'suivable_id' => DB::raw('commande_id'),
        ]);

        Schema::table('track_orders', function (Blueprint $table) {
            // Une course de location n'a pas de commande, et sa position
            // client vit sur la reservation : les deux colonnes deviennent
            // facultatives.
            $table->unsignedBigInteger('commande_id')->nullable()->change();
            $table->json('location_customer')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('track_orders', function (Blueprint $table) {
            $table->dropMorphs('suivable');
        });
    }
};
