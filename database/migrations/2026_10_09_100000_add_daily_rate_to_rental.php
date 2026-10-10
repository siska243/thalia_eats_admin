<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La location se facture a l'heure OU a la journee.
 *
 * Le client choisit son mode au moment de reserver, et chaque mode facture
 * ses propres unites entamees : il n'y a pas de melange. A 10 $ l'heure et
 * 149 $ la journee, vingt-quatre heures facturees a l'heure coutaient 240 $ ;
 * sans ce mode, le tarif journalier n'aurait ete qu'un affichage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            /*
             * Nullable : un vehicule peut n'etre propose qu'a l'heure. Le mode
             * journee ne s'affiche alors pas pour lui, plutot que de montrer
             * un prix a zero.
             */
            $table->decimal('daily_rate', 10, 2)->nullable()->after('hourly_rate');
        });

        Schema::table('bookings', function (Blueprint $table) {
            // Fige sur la reservation : changer un tarif ne doit pas changer
            // le prix d'une course deja passee.
            $table->string('pricing_mode')->default('hourly')->after('hourly_rate');
            $table->decimal('unit_price', 10, 2)->nullable()->after('pricing_mode');
            $table->decimal('billed_units', 8, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn('daily_rate'));

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['pricing_mode', 'unit_price', 'billed_units']);
        });
    }
};
