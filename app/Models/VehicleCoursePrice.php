<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le tarif d'un vehicule pour un type de course.
 *
 * Un modele a part entiere et non une simple table de liaison : la ligne porte
 * ses propres montants, l'administration la saisit directement, et Filament ne
 * sait pas ecrire des donnees de pivot depuis un repeteur. Une seule facon
 * d'atteindre ces lignes, donc, plutot qu'une relation pivot doublee d'une
 * relation directe.
 *
 * Les deux tarifs sont nullables : laisse vide, le vehicule garde son prix
 * habituel.
 */
class VehicleCoursePrice extends Model
{
    protected $table = 'vehicle_course_prices';

    protected $guarded = ['id'];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'daily_rate' => 'decimal:2',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function courseType(): BelongsTo
    {
        return $this->belongsTo(CourseType::class);
    }
}
