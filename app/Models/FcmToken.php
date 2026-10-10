<?php

namespace App\Models;

use Database\Factories\FcmTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FcmToken extends Model
{
    /** @use HasFactory<FcmTokenFactory> */
    use HasFactory;

    public function user():BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
