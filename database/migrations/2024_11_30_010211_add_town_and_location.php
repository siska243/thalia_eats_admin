<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            //
            $table->json('location')->nullable();
            $table->string('email')->nullable();
        });

        Schema::table('commandes', function (Blueprint $table) {
            //
            $table->time('time_restaurant')->nullable();
            $table->time('time_delivery')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            //
            $table->dropColumn('location');
            $table->dropColumn('email');
        });

        Schema::table('commandes', function (Blueprint $table) {
            //
            $table->dropColumn('time_restaurant');
            $table->dropColumn('time_delivery');
        });
    }
};
