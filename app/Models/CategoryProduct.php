<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryProduct extends Model
{
    use HasFactory;

    public function sub_category_product():HasMany
    {
        return $this->hasMany(SubCategoryProduct::class);
    }
}
