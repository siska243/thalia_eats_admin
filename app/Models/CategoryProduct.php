<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CategoryProduct extends Model
{
    use HasFactory,softDeletes;

    public function sub_category_product():HasMany
    {
        return $this->hasMany(SubCategoryProduct::class);
    }

    public function products():HasMany
    {
        return $this->hasMany(Product::class);
    }
}
