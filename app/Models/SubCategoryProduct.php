<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubCategoryProduct extends Model
{
    use HasFactory;

    public function category_product():BelongsTo
    {
        return $this->belongsTo(CategoryProduct::class, 'category_product_id');
    }

    public function product():HasMany
    {
        return $this->hasMany(Product::class);
    }
    public function restaurant():HasMany{
     return $this->hasMany(Restaurant::class);
    }
}
