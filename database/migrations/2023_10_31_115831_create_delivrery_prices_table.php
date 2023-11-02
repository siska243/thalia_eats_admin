<?php

use App\Models\Currency;
use App\Models\Town;
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
        Schema::create('delivrery_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Town::class,'town_id');
            $table->integer('interval_pricing');
            $table->float('frais');
            $table->foreignIdFor(Currency::class,'currency_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivrery_prices');
    }
};
