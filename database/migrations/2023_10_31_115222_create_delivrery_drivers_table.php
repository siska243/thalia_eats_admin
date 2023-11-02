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
        Schema::create('delivrery_drivers', function (Blueprint $table) {
            $table->id();
            $table->date('birth_date')->nullable();
            $table->string('id_card',255);
            $table->longText('contract')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivrery_drivers');
    }
};
