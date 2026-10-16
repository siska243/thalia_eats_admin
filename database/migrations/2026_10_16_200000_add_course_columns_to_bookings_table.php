<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le type de course sur la reservation, et le detail du prix.
 *
 * `course_type_id` NULLABLE : les applications deja installees n'envoient pas
 * de type, et leurs reservations sont des courses urbaines ordinaires. Les
 * refuser faute de type casserait le parc pour un controle qu'il ne sait pas
 * encore satisfaire — meme raisonnement que `pickup_town_id`.
 *
 * `course_note` n'est PAS obligatoire en base, alors que le type peut
 * l'exiger du client : une reservation prise au comptoir par l'administration
 * ne doit pas etre bloquee par un commentaire manquant. L'exigence est une
 * regle de formulaire, pas une contrainte de colonne.
 *
 * `base_price` et `parking_fee` disent d'ou vient le total. Sans eux, une
 * course aeroport afficherait 90 $ la ou le catalogue annonce 60, et le client
 * croirait qu'on a change le prix derriere son dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('course_type_id')->nullable()->after('vehicle_id')
                ->constrained('course_types')->nullOnDelete();
            $table->text('course_note')->nullable()->after('course_type_id');
            $table->boolean('parking')->default(false)->after('course_note');
            $table->decimal('base_price', 10, 2)->nullable()->after('duration_hours');
            $table->decimal('parking_fee', 10, 2)->default(0)->after('base_price');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_type_id');
            $table->dropColumn(['course_note', 'parking', 'base_price', 'parking_fee']);
        });
    }
};
