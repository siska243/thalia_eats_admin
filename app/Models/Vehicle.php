<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'daily_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'minimum_hours' => 'integer',
        'deposit_percentage' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $vehicle) {
            $vehicle->slug ??= Str::slug("{$vehicle->brand} {$vehicle->model} {$vehicle->plate_number}");
            $vehicle->reference ??= 'V-'.Str::upper(Str::random(8));
        });
    }

    /**
     * La ville ou ce vehicule travaille.
     *
     * Nulle pour le parc existant : sans ville, il se reserve partout. C'est
     * la saisie en administration qui le rattache.
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Les tarifs propres a ce vehicule, par type de course.
     *
     * Une ligne absente, ou un tarif nul, et le vehicule garde son prix
     * habituel : « tu mets le prix par defaut comme il est maintenant et
     * ensuite tu ajoutes le prix en fonction du type ». Le parc existant
     * fonctionne donc sans aucune saisie.
     */
    /**
     * Les communes d'ou ce vehicule accepte une course.
     *
     * VIDE veut dire « toutes celles de sa ville » : « aucune commune
     * selectionnee, on prend toutes les communes ». Une liste vide ne ferme
     * donc rien, exactement comme une ville nulle.
     */
    public function towns(): BelongsToMany
    {
        return $this->belongsToMany(Town::class, 'vehicle_town')->withTimestamps();
    }

    /**
     * Ce vehicule accepte-t-il une course au depart de cette commune ?
     *
     * **La regle vit ici, une seule fois.** La reservation et le catalogue
     * posent la meme question, et deux lectures finiraient par diverger —
     * c'est deja arrive sur les horaires et sur la portee.
     *
     * Elle ne juge QUE les communes : la ville est tranchee avant, et son
     * refus prime. Une commune cochee d'une autre ville ne rouvre donc rien.
     */
    public function dessertLaCommune(?int $townId): bool
    {
        if (! $townId) {
            return true;
        }

        $communes = $this->relationLoaded('towns')
            ? $this->towns->pluck('id')
            : $this->towns()->pluck('towns.id');

        return $communes->isEmpty() || $communes->contains($townId);
    }

    public function coursePrices(): HasMany
    {
        return $this->hasMany(VehicleCoursePrice::class);
    }

    /**
     * Le tarif du parking pour ce type, sur ce vehicule.
     *
     * Celui du vehicule s'il est saisi, celui du type sinon. ZERO est une
     * valeur a part entiere — « le parking est offert » — et c'est pourquoi la
     * colonne est nullable plutot que defaut zero : un tarif absent laisse le
     * type decider, un zero saisi dit que le client ne paie pas.
     *
     * C'est le TYPE qui decide si l'option existe : un type sans option ne
     * facture rien, quel que soit le tarif saisi sur le vehicule.
     */
    public function tarifParkingPour(?CourseType $type): float
    {
        if (! $type?->parking_option) {
            return 0.0;
        }

        $ligne = $this->relationLoaded('coursePrices')
            ? $this->coursePrices->firstWhere('course_type_id', $type->id)
            : $this->coursePrices()->where('course_type_id', $type->id)->first();

        return $ligne?->parking_hourly_fee !== null
            ? (float) $ligne->parking_hourly_fee
            : $type->tarifParking();
    }

    /**
     * Le tarif de ce vehicule pour ce type et ce mode, s'il en a un.
     *
     * **La lecture vit ici, une seule fois.** Le devis, le catalogue et
     * l'administration posent la meme question ; trois lectures finiraient
     * par diverger, comme cela s'est deja produit sur les horaires.
     */
    public function tarifPour(?CourseType $type, string $mode): ?float
    {
        if (! $type) {
            return null;
        }

        $ligne = $this->relationLoaded('coursePrices')
            ? $this->coursePrices->firstWhere('course_type_id', $type->id)
            : $this->coursePrices()->where('course_type_id', $type->id)->first();

        $tarif = $mode === 'daily' ? $ligne?->daily_rate : $ligne?->hourly_rate;

        return $tarif !== null && (float) $tarif > 0 ? (float) $tarif : null;
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
