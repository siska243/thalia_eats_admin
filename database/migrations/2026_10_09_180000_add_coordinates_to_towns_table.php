<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les coordonnees d'une commune.
 *
 * Nullables : les communes deja en base n'en ont pas, et la carte doit
 * continuer a fonctionner sans elles. Six decimales suffisent — environ dix
 * centimetres — pour un centroide de commune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('towns', function (Blueprint $table) {
            $table->decimal('latitude', 8, 6)->nullable()->after('zip');
            $table->decimal('longitude', 11, 6)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('towns', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
