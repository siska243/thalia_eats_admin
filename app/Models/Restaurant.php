<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Restaurant extends Model
{
    use HasFactory;
    protected $casts = [
        'openHours' => 'array',
    ];
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function town():BelongsTo
    {
        return $this->belongsTo(Town::class,'town_id');
    }

    public function product():HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function sub_categorie_product():BelongsTo
    {
 return $this->belongsTo(SubCategoryProduct::class);
    }
}
