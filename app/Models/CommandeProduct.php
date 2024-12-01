<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommandeProduct extends Model
{
    use HasFactory;

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user():BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function delivery_driver(): BelongsTo
    {
        return $this->belongsTo(DelivreryDriver::class);
    }

}
