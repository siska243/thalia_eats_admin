<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les demandes de partenariat, restaurant ou livreur.
 *
 * Ce ne sont **pas** des inscriptions : aucun compte n'est cree, aucun acces
 * n'est donne. La societe rappelle, et c'est tout ce que cette table sert a
 * organiser. Les deux boutons du site, « Commencer comme restaurant » et
 * « Roulez avec nous », pointaient sur la page d'accueil : ils promettaient
 * une suite qui n'existait pas.
 *
 * Une seule table pour les deux metiers, avec `type` et une colonne JSON pour
 * ce qui leur est propre. Deux tables auraient duplique le statut, les notes
 * et l'ecran d'administration pour ne changer que trois champs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();

            $table->string('type', 20)->index();

            // Les seuls champs que tout prospect porte, et les seuls sur
            // lesquels on cherche : un commercial retrouve une demande par le
            // nom ou par le telephone.
            $table->string('nom');
            $table->string('telephone');
            $table->string('email')->nullable();
            $table->string('commune')->nullable();

            /*
             * Ce qui change d'un metier a l'autre : l'etablissement et la
             * cuisine d'un cote, le vehicule, le permis et la disponibilite de
             * l'autre. En JSON plutot qu'en colonnes, parce que la prospection
             * n'en fait rien d'autre que les lire, et qu'ajouter une question
             * au formulaire ne doit pas demander une migration.
             */
            $table->json('details')->nullable();

            $table->text('message')->nullable();

            // L'avancement, demande par le proprietaire : sans lui, deux
            // personnes rappellent le meme restaurant.
            $table->string('statut', 20)->default('nouvelle')->index();
            $table->text('notes')->nullable();
            $table->timestamp('contacte_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospects');
    }
};
