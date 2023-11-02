<?php

use App\Models\CommandeProduct;
use App\Models\DelivreryDriver;
use App\Models\Status;
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
        Schema::create('commandes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Status::class,'status_id')->nullable();
            $table->string('refernce')->unique();
            $table->float('global_price')->nullable();
            $table->float('price_delivery')->nullable();
            $table->foreignIdFor(DelivreryDriver::class,'delivrery_driver_id')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('delivery_at')->nullable();
            $table->timestamp('paied_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commandes');
    }
};
