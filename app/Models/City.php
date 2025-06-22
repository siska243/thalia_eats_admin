<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class City extends Model
{
    /** @use HasFactory<\Database\Factories\CityFactory> */
    use HasFactory;

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Model $model) {

            $model->slug=Str::slug($model->title);
        });

        static::updating(function (Model $model) {
            $model->slug=Str::slug($model->title);
        });
    }
}
