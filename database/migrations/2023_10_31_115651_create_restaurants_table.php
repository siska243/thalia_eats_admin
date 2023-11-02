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
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class,'user_id')->nullable();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('adresse');
            $table->longText('description')->nullable();
            $table->string('reference');
            $table->json('openHours')->nullable();
            $table->boolean('is_active')->default(false);
            $table->longText('banniere')->nullable();
            $table->string('phone');
            $table->string('whatsapp');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
