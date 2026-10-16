<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qu'un vehicule peut regler pour lui-meme.
 *
 * Le proprietaire, le 16 octobre : « en gros tout ce qui est possible de faire
 * pour une location de vehicule doit etre configurable ».
 *
 * Trois reglages, tous NULLABLES ou poses a la valeur d'aujourd'hui : un
 * vehicule qui ne dit rien garde exactement le comportement actuel, et le parc
 * existant n'a rien a saisir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            /*
             * La duree minimale propre a ce vehicule, en heures.
             *
             * Nulle : seuls les reglages generaux et le type de course
             * s'appliquent. Un 4x4 peut exiger deux journees la ou une
             * citadine se loue a l'heure.
             */
            $table->unsignedSmallInteger('minimum_hours')->nullable()->after('daily_rate');

            /*
             * L'acompte propre a ce vehicule, en pourcentage.
             *
             * Nul : c'est le pourcentage general des reglages. Un vehicule
             * cher peut en demander davantage.
             */
            $table->decimal('deposit_percentage', 5, 2)->nullable()->after('minimum_hours');

        });

        /*
         * PAS de colonne « propose a l'heure / a la journee ».
         *
         * Le proprietaire, le 16 octobre : « si le prix est defini et
         * superieur a zero, la location a la journee ou a l'heure s'active ».
         * Le TARIF est la decision : une case en plus serait un second etat a
         * tenir coherent avec le premier, et un vehicule pourrait se retrouver
         * coche sans prix, ou l'inverse.
         */

        Schema::table('vehicle_course_prices', function (Blueprint $table) {
            /*
             * Le tarif du parking pour CE vehicule et CE type.
             *
             * Nul : celui du type s'applique. ZERO est une valeur a part
             * entiere — « le parking est offert, le client ne paie pas » — et
             * c'est pourquoi la colonne est nullable plutot que defaut zero.
             */
            $table->decimal('parking_hourly_fee', 10, 2)->nullable()->after('daily_rate');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['minimum_hours', 'deposit_percentage']);
        });

        Schema::table('vehicle_course_prices', function (Blueprint $table) {
            $table->dropColumn('parking_hourly_fee');
        });
    }
};
