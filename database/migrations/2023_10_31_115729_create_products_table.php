<?php

use App\Models\Restaurant;
use App\Models\SubCategoryProduct;
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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->text('title');
            $table->longText('description')->nullable();
            $table->float('price');
            $table->float('promotionnalPrice')->nullable();
            $table->foreignIdFor(Restaurant::class,'restaurant_id');
            $table->foreignIdFor(SubCategoryProduct::class, 'sub_category_product_id');
            $table->string('slug')->unique();
            $table->text('picture')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
