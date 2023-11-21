<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Town extends Model
{
    use HasFactory;

    public function restaurant() : HasMany
    {
        return $this->hasMany(Restaurant::class,'restaurant_id');
    }

    public function delivrery_price():HasMany{
        return $this->hasMany(DelivreryPrice::class);
    }
}
