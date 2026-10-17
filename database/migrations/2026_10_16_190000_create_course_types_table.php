<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les types de course : un REFERENTIEL, pas une enumeration.
 *
 * Le proprietaire, le 16 octobre : « le type c'est un referentiel ». Comme la
 * table des statuts de commande, il s'edite en administration : ajouter un
 * quatrieme type ne doit demander aucune livraison.
 *
 * D'ou les regles portees par la ligne elle-meme plutot que par du code :
 * duree minimale, mode impose, commentaire obligatoire, option parking. Un
 * `if ($type === 'B')` quelque part dans le code aurait annule tout l'interet
 * d'un referentiel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('title');
            $table->string('description')->nullable();

            /*
             * La duree minimale, en heures. « Pour les courses en dehors de la
             * ville il est oblige de reserver pour minimum 24h. » En heures et
             * non en journees : c'est l'unite dans laquelle le creneau est
             * mesure, et convertir deux fois finit par deriver.
             */
            $table->unsignedSmallInteger('minimum_hours')->nullable();

            /*
             * Le mode impose, `hourly` ou `daily`. Nul : le client choisit.
             * Impose, on REFUSE l'autre mode au lieu de basculer en silence —
             * « le client a choisi un mode et un prix, et un autre prix n'est
             * pas le sien ».
             */
            $table->string('forced_mode', 16)->nullable();

            /** « Ils doivent prevenir si c'est pour quel type de course. » */
            $table->boolean('note_required')->default(false);

            /*
             * L'option parking, proposee au client et facturee a l'heure.
             * « Le vehicule ne rentre pas a l'aeroport, avec 10 $ de plus par
             * heure pour le parking. » C'est un choix, pas une fatalite.
             */
            $table->boolean('parking_option')->default(false);
            $table->decimal('parking_hourly_fee', 10, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        /*
         * Le prix d'un vehicule POUR un type de course.
         *
         * « Tu mets le prix par defaut comme il est maintenant et ensuite tu
         * ajoutes le prix en fonction du type. » Les deux colonnes sont donc
         * nullables : une ligne absente, ou un tarif nul, et le vehicule garde
         * son prix habituel. Le parc existant fonctionne sans aucune saisie.
         */
        Schema::create('vehicle_course_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('daily_rate', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['vehicle_id', 'course_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_course_prices');
        Schema::dropIfExists('course_types');
    }
};
