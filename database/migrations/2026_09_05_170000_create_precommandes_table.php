<?php

use App\Models\Commande;
use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('precommandes', function (Blueprint $table) {
            $table->id();
            $table->string('refernce')->unique();

            $table->foreignIdFor(User::class, 'user_id');
            $table->foreignIdFor(Restaurant::class, 'restaurant_id');
            $table->foreignIdFor(Town::class, 'town_id');

            $table->string('adresse_delivery');
            $table->string('street')->nullable();
            $table->string('number_street')->nullable();
            $table->string('reference_adresse')->nullable();
            $table->float('lat')->nullable();
            $table->float('long')->nullable();

            $table->string('recipient_name');
            $table->string('recipient_phone');

            $table->float('sous_total');
            $table->float('frais_livraison');
            $table->float('service_price');
            $table->float('total');
            $table->foreignIdFor(Currency::class, 'currency_id');
            $table->foreignIdFor(DelivreryPrice::class, 'delivrery_price_id')->nullable();

            $table->timestamp('expires_at');
            $table->string('status')->default('en_attente');
            $table->string('reference_paiement')->nullable();
            $table->foreignIdFor(Commande::class, 'commande_id')->nullable();
            $table->timestamp('paied_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('precommande_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('precommande_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Product::class, 'product_id');
            $table->float('quantity');
            $table->float('price');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('precommande_products');
        Schema::dropIfExists('precommandes');
    }
};
