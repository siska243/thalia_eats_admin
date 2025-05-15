<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payement extends Model
{
    /** @use HasFactory<\Database\Factories\PayementFactory> */
    use HasFactory,SoftDeletes;


    protected $fillable = [
        'code',
        'status_payement_id',
        'order_created_at',
        'currency',
        'commande_id',
        'provider_reference',
        'phone',
        'amount',
        'amount_customer',
        'channel',
        'reference',
        "webhook_sse_url"
    ];

    public function statusPayement():BelongsTo
    {
        return $this->belongsTo(StatusPayement::class);
    }

    public function commande():BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }
}
