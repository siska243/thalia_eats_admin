<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrecommandeProduct extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function precommande(): BelongsTo
    {
        return $this->belongsTo(Precommande::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
