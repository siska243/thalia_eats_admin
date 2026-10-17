<?php

use App\Models\Commande;
use App\Models\StatusPayement;
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
        Schema::create('payements', function (Blueprint $table) {
            $table->id();
            $table->timestamp('order_created_at')->nullable();
            $table->string('currency')->nullable();
            $table->float('amount')->default(0);
            $table->float('amount_customer')->default(0);
            $table->string('channel')->nullable();
            $table->string('order_number')->nullable();
            $table->string('reference')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('phone')->nullable();
            $table->string('code')->nullable();
            $table->foreignIdFor(Commande::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(StatusPayement::class)->constrained()->cascadeOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payements');
    }
};
