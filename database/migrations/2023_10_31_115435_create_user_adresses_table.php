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
        Schema::create('user_adresses', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class,'user_id')->constrained()->cascadeOnUpdate();
            $table->text('adresse');
            $table->string('reference');
            $table->string('slug')->uniqid();
            $table->boolean('is_main')->default(false);
            $table->float('lat')->nullable();
            $table->float('long')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_adresses');
    }
};
