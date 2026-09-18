<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Precommande extends Model
{
    use HasFactory;

    public const STATUT_EN_ATTENTE = 'en_attente';

    public const STATUT_PAYEE = 'payee';

    public const STATUT_EXPIREE = 'expiree';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'paied_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id');
    }

    public function town(): BelongsTo
    {
        return $this->belongsTo(Town::class, 'town_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(PrecommandeProduct::class);
    }

    /**
     * Expiration paresseuse : le scheduler ne tourne pas en production, donc
     * aucun balayage ne peut être le mécanisme. On dérive de expires_at.
     */
    public function estExpiree(): bool
    {
        return $this->status === self::STATUT_EN_ATTENTE
            && $this->expires_at->isPast();
    }

    public function estValide(): bool
    {
        return $this->status === self::STATUT_EN_ATTENTE
            && $this->expires_at->isFuture();
    }

    /**
     * L'assistant ne collecte que la commune : les coordonnées de livraison
     * sont saisies par le client sur la page de paiement. Cette méthode dit si
     * elles le sont déjà — elle décide ce que le formulaire affiche, et
     * interdit d'écraser des coordonnées figées.
     */
    public function coordonneesCompletes(): bool
    {
        return filled($this->adresse_delivery)
            && filled($this->recipient_name)
            && filled($this->recipient_phone);
    }

    /**
     * @param  Builder<Precommande>  $query
     */
    public function scopeValides(Builder $query): void
    {
        $query->where('status', self::STATUT_EN_ATTENTE)->where('expires_at', '>', now());
    }
}
