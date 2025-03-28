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
        Schema::table('users', function (Blueprint $table) {
            //
            $table->string('street')->nullable();
            $table->string('number_street')->nullable();
        });
        Schema::table('commandes', function (Blueprint $table) {
            //
            $table->string('street')->nullable();
            $table->string('number_street')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
            $table->dropColumn('street');
            $table->dropColumn('number_street');
        });

        Schema::table('commandes', function (Blueprint $table) {
            //
            $table->dropColumn('street');
            $table->dropColumn('number_street');
        });
    }
};
