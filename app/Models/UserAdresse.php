<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Adresse de livraison enregistree d'un client.
 *
 * La table existait depuis l'origine mais n'etait alimentee nulle part :
 * update_adresse ecrit dans la table users, si bien qu'un client n'avait
 * qu'une seule adresse, ecrasee a chaque modification. Elle sert desormais
 * d'historique reutilisable, alimente a chaque commande.
 */
class UserAdresse extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'adresse',
        'reference',
        'slug',
        'is_main',
        'lat',
        'long',
        'town_id',
        'street',
        'number_street',
        'label',
    ];

    protected $casts = [
        'is_main' => 'boolean',
        'lat' => 'float',
        'long' => 'float',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Model $model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug(Str::limit($model->adresse ?? 'adresse', 30, '')).'-'.uniqid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function town(): BelongsTo
    {
        return $this->belongsTo(Town::class, 'town_id');
    }
}
