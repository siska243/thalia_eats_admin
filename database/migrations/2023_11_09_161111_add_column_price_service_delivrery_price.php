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
        Schema::table('delivrery_prices', function (Blueprint $table) {
            //
            $table->float('service_price')->nullable();
            $table->boolean('is_active')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivrery_prices', function (Blueprint $table) {
            //
            $table->dropColumn('service_price');
            $table->dropColumn('is_active');
        });
    }
};
