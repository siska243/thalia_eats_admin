<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** Un vehicule loue avec chauffeur, facture a l'heure. */
class Vehicle extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $vehicle) {
            $vehicle->slug ??= Str::slug("{$vehicle->brand} {$vehicle->model} {$vehicle->plate_number}");
            $vehicle->reference ??= 'V-' . Str::upper(Str::random(8));
        });
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'vehicle_id');
    }

    /**
     * Le chauffeur attitre.
     *
     * Une valeur par defaut, pas une affectation ferme : celle-ci vit sur la
     * reservation, parce qu'un vehicule change de conducteur selon les jours.
     */
    public function defaultChauffeur(): BelongsTo
    {
        return $this->belongsTo(Chauffeur::class, 'default_chauffeur_id');
    }

    /** Les vehicules qu'on peut proposer a la reservation. */
    public function scopeRentable(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getNameAttribute(): string
    {
        return trim("{$this->brand} {$this->model}");
    }
}
