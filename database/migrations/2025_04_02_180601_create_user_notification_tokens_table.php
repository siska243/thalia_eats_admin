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
        Schema::create('user_notification_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token');
            $table->foreignIdFor(\App\Models\User::Class)->constrained()->cascadeOnDelete();
            $table->boolean('is_current')->default(false);
            $table->json('device')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_notification_tokens');
    }
};
