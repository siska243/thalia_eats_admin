<?php

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
        Schema::table('commandes', function (Blueprint $table) {
            //
            $table->timestamp('accepted_at')->nullable();
            $table->string('adresse_delivery')->nullable();
            $table->string('reference_adresse')->nullable();
            $table->float('price_service')->nullable();
            $table->foreignIdFor(Town::class,'town_id')->nullable();
            $table->boolean('reception')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            //
            $table->dropColumn('accepted_at');
            $table->dropColumn('adresse_delivery');
            $table->dropColumn('reference_adresse');
            $table->dropColumn('price_service');
            $table->dropColumn(Town::class, 'town_id');
            $table->dropColumn('reception');
        });
    }
};
