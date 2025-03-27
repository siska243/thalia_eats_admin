<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StatusPayement extends Model
{
    /** @use HasFactory<\Database\Factories\StatusPayementFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'color',
        'is_default',
        'is_paid',
    ];

    protected $casts=[
        'is_default'=>'boolean',
        'is_paid'=>'boolean',
    ];
}
