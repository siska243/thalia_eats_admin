<?php

namespace App\Models;

use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        // Caste en float et non en decimal: : le rayon se compare a une
        // distance calculee, jamais a une chaine. `decimal:2` aurait rendu
        // une string et fait echouer un identique sur un float.
        'max_delivery_radius_km' => 'float',
    ];

    /**
     * Les communes de cette ville.
     *
     * Une ville est Kinshasa ou Lubumbashi ; une commune est Gombe ou Limete.
     * La relation existait dans l'autre sens seulement (`Town::city()`).
     */
    public function towns(): HasMany
    {
        return $this->hasMany(Town::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Model $model) {

            $model->slug = Str::slug($model->title);
        });

        static::updating(function (Model $model) {
            $model->slug = Str::slug($model->title);
        });
    }
}
