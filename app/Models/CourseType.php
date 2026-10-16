<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un type de course.
 *
 * REFERENTIEL et non enumeration : « le type c'est un referentiel ». Chaque
 * ligne porte ses propres regles, et c'est ce qui permet d'ajouter un
 * quatrieme type depuis l'administration, sans livraison. Un `if ($code ===
 * 'B')` quelque part dans le code annulerait tout l'interet de la table.
 */
class CourseType extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'minimum_hours' => 'integer',
        'note_required' => 'boolean',
        'parking_option' => 'boolean',
        'parking_hourly_fee' => 'decimal:2',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    /** Les tarifs saisis pour ce type, vehicule par vehicule. */
    public function vehiclePrices(): HasMany
    {
        return $this->hasMany(VehicleCoursePrice::class);
    }

    public function scopeActifs(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position');
    }

    /**
     * Le tarif parking effectif, zero quand l'option n'existe pas.
     *
     * Un type sans option ne facture rien, meme si l'appelant demande le
     * parking : c'est le referentiel qui decide, pas la requete.
     */
    public function tarifParking(): float
    {
        return $this->parking_option ? (float) $this->parking_hourly_fee : 0.0;
    }
}
