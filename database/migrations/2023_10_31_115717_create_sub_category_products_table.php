<?php

use App\Models\CategoryProduct;
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
        Schema::create('sub_category_products', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(CategoryProduct::class,'category_id');
            $table->text('title');
            $table->string('slug')->unique();
            $table->text('picture')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_category_products');
    }
};
