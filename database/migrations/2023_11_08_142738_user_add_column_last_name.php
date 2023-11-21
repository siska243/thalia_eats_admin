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
        Schema::table('users', function (Blueprint $table) {
            //
            $table->string('last_name')->nullable();
            $table->string('principal_adresse')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignIdFor(Town::class,'town_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
            $table->dropColumn('last_name');
            $table->dropColumn('principal_adresse');
            $table->dropForeignIdFor(Town::class,'town_id');
            $table->dropColumn('is_active');
        });
    }
};
