<?php

use App\Models\User;
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
        Schema::table('delivrery_drivers', function (Blueprint $table) {
            //
            $table->foreignIdFor(User::class,'user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivrery_drivers', function (Blueprint $table) {
            //
            $table->dropForeignIdFor(User::class,'user_id');
        });
    }
};
