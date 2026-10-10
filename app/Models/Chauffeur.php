<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role;

/**
 * Un chauffeur de location.
 *
 * Distinct de `DelivreryDriver` : pas le meme metier, pas le meme permis, pas
 * les memes ecrans. Il dispose d'un compte pour consulter ses courses et son
 * historique, et ne peut pas refuser une affectation.
 */
class Chauffeur extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'chauffeurs';

    protected $guarded = [];

    protected $casts = [
        'licence_expires_at' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Le role `chauffeurs` suit la fiche, il ne se pose pas a la main.
     *
     * L'application mobile route sur les roles rendus par la connexion, comme
     * elle le fait deja pour `drivers`. Laisser l'administrateur y penser
     * aurait produit ce qu'on a deja vu sur les livreurs : une fiche creee,
     * un compte qui ouvre l'application cliente, et personne pour comprendre
     * pourquoi.
     */
    protected static function booted(): void
    {
        static::saved(function (self $chauffeur) {
            if (! $chauffeur->user) {
                return;
            }

            // `findOrCreate` et non `assignRole('chauffeurs')` : le role peut
            // ne pas exister encore — base fraiche, migration rejouee a
            // l'envers, suite de tests qui vide la table — et `assignRole`
            // leve alors une exception qui ferait echouer l'enregistrement de
            // la fiche. Le role est une consequence, il n'a pas a faire
            // tomber la cause.
            $chauffeur->user->assignRole(Role::findOrCreate('chauffeurs', 'web'));
        });

        static::deleted(function (self $chauffeur) {
            $chauffeur->user?->removeRole('chauffeurs');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'chauffeur_id');
    }

    /** Les vehicules dont il est le conducteur habituel. */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'default_chauffeur_id');
    }

    /**
     * Les chauffeurs qu'on peut affecter.
     *
     * Un permis perime disqualifie : conduire un client avec un permis expire
     * engage l'entreprise, et personne ne verifie une date a la main dans une
     * liste deroulante.
     */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereNull('licence_expires_at')
                ->orWhereDate('licence_expires_at', '>=', now()));
    }

    public function hasValidLicence(): bool
    {
        return $this->licence_expires_at === null
            || $this->licence_expires_at->isFuture()
            || $this->licence_expires_at->isToday();
    }
}
