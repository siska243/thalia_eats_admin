<?php

namespace App\Models;

use Database\Factories\ConfigurationPayementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConfigurationPayement extends Model
{
    /** @use HasFactory<ConfigurationPayementFactory> */
    use HasFactory,SoftDeletes;

    protected $fillable=[
      "token",
      "token_key",
      "active",
      "environment",
      "url",
      "url_doc",
      "user_id"
    ];

    protected $casts=[
        "active"=>"boolean",
    ];

    public function user():BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
