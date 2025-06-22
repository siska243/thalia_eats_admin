<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Commande extends Model
{
    use HasFactory;




    public function user():BelongsTo
    {
        return $this->belongsTo(User::class,'user_id');
    }
    public function product():HasMany
    {
        return $this->hasMany(CommandeProduct::class);
    }
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }
    public function town(): BelongsTo
    {
        return $this->belongsTo(Town::class);
    }
    public function commande_products(): HasMany
    {
        return $this->hasMany(CommandeProduct::class);
    }
    public function delivrery_driver():BelongsTo
    {
        return $this->belongsTo(DelivreryDriver::class);
    }
}
