<?php

use Database\Seeders\CourseTypeSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Les trois types de course arrivent en production par une MIGRATION.
 *
 * Sans elle, ils n'y arriveraient jamais : `deploy/entrypoint.sh` et
 * `deploy/deploy.sh` ne lancent QUE `migrate --force`, et jamais `db:seed` —
 * c'est une decision ecrite dans les deux fichiers, parce qu'un seeder non
 * idempotent dupliquerait les donnees importees.
 *
 * Le referentiel serait donc reste vide en production, et tout le choix de
 * type de course invisible dans les applications, alors que le code est livre
 * et teste. Le genre de manque qui ne se voit qu'une fois deploye.
 *
 * Elle APPELLE le seeder au lieu de recopier les lignes : une seule definition
 * des trois types, et une installation neuve comme un deploiement passent par
 * le meme code. Le seeder est idempotent (`firstOrCreate` sur le code), donc
 * rejouer ne double rien et n'ecrase aucun tarif ajuste en administration.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new CourseTypeSeeder)->run();
    }

    /**
     * Rien a defaire.
     *
     * Supprimer les types effacerait ceux que l'administration a pu ajouter,
     * et les reservations qui les portent perdraient leur reference. Un retour
     * arriere de schema ne doit pas emporter des donnees metier.
     */
    public function down(): void {}
};
