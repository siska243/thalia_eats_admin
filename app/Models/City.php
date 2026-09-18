<?php

namespace App\Models;

use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class City extends Model
{
    /** @use HasFactory<CityFactory> */
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
