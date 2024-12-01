<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory;
    use SoftDeletes;
    public function restaurant():BelongsTo
    {
        return $this->belongsTo(Restaurant::class,'restaurant_id');
    }

    public function currency():BelongsTo
    {
        return $this->belongsTo(Currency::class,'currency_id');
    }

    public function sub_category_product(): BelongsTo
    {
      return $this->belongsTo(SubCategoryProduct::class,'sub_category_product_id');
    }

    public function commande_product(): HasMany
    {
        return $this->hasMany(CommandeProduct::class);
    }
}
