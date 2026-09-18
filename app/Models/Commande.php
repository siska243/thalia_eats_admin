<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Commande extends Model
{
    use HasFactory;

    /*
     * Identifiants de la table status. Ils sont ecrits ici, une fois, parce
     * qu'ils etaient dissemines en nombres nus dans les controleurs — un
     * `whereIn('status_id', [1, 5])` ne dit pas ce qu'il cherche.
     */
    public const STATUT_EN_ATTENTE = 1;
    public const STATUT_EN_COURS = 2;
    public const STATUT_LIVREE = 3;
    public const STATUT_ANNULEE = 4;
    public const STATUT_ATTENTE_PAIEMENT = 5;

    /** Les statuts d'une commande qui n'est ni livree ni annulee. */
    public const STATUTS_EN_COURS = [
        self::STATUT_EN_ATTENTE,
        self::STATUT_EN_COURS,
        self::STATUT_ATTENTE_PAIEMENT,
    ];

    /**
     * Au-dela de ce delai, une commande toujours en cours est consideree
     * abandonnee.
     *
     * Deux jours : un repas ne se livre pas le surlendemain, et une commande
     * qui traine si longtemps bloque le client — il ne peut pas en passer une
     * nouvelle tant que la precedente n'est pas reglee.
     */
    public const JOURS_AVANT_ABANDON = 2;

    /**
     * Une commande annulee doit le dire des deux façons.
     *
     * L'annulation s'ecrit a deux endroits — le statut et cancel_at — et le
     * formulaire d'administration les expose comme deux champs independants.
     * Un administrateur qui renseigne la date sans toucher au statut laissait
     * une commande annulee se presenter comme vivante : au livreur comme sa
     * course en cours, au restaurateur comme un plat a preparer, au client
     * comme un repas en route.
     *
     * Plutot que de rattraper l'ecart dans chaque lecture — il y en avait six,
     * la septieme aurait hérité du meme defaut — on le rend impossible a
     * l'ecriture.
     */
    protected static function booted(): void
    {
        static::saving(function (self $commande) {
            if ($commande->cancel_at && (int) $commande->status_id !== self::STATUT_ANNULEE) {
                $commande->status_id = self::STATUT_ANNULEE;
            }

            if ((int) $commande->status_id === self::STATUT_ANNULEE && !$commande->cancel_at) {
                $commande->cancel_at = now();
            }
        });
    }

    /**
     * Les commandes encore vivantes.
     *
     * Les deux clauses font double emploi une fois la garantie d'ecriture en
     * place ; elles restent parce que les lignes ecrites avant elle, comme la
     * commande annulee en juillet 2025, sont toujours en base.
     */
    /**
     * Les commandes du client qui restent a regler : en attente (1) ou en
     * attente de paiement (5), et non annulees.
     *
     * Cette definition etait ecrite HUIT fois dans CommandeController, sous
     * la forme whereIn('status_id', [1, 5]). Ajouter nonAnnulee() a
     * l'affichage sans l'ajouter au controle de creation a suffi a les faire
     * divergier : une commande portant cancel_at avec le statut reste a 5 —
     * une des lignes heritees incoherentes — disparaissait de « Commandes en
     * cours » tout en continuant de bloquer toute nouvelle commande. Le
     * client se retrouvait sans issue : rien a annuler a l'ecran, et rien de
     * possible non plus.
     */
    public function scopeNonReglee(Builder $query): Builder
    {
        return $query->whereIn('status_id', [1, 5])->nonAnnulee();
    }

    public function scopeNonAnnulee(Builder $query): Builder
    {
        return $query
            ->whereNull('cancel_at')
            ->where('status_id', '!=', self::STATUT_ANNULEE);
    }

    /**
     * Les commandes restees en cours au-dela du delai d'abandon.
     *
     * `delivery_at` est verifie en plus du statut : les deux ont diverge sur
     * des lignes anciennes, et une commande effectivement livree ne doit pas
     * etre annulee parce que son statut n'a pas suivi.
     */
    public function scopeAbandonnee(Builder $query): Builder
    {
        return $query
            ->whereIn('status_id', self::STATUTS_EN_COURS)
            ->whereNull('delivery_at')
            ->nonAnnulee()
            ->where('created_at', '<', now()->subDays(self::JOURS_AVANT_ABANDON));
    }


    public function user():BelongsTo
    {
        return $this->belongsTo(User::class,'user_id');
    }
    public function product():HasMany
    {
        return $this->hasMany(CommandeProduct::class);
    }
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }
    public function town(): BelongsTo
    {
        return $this->belongsTo(Town::class);
    }
    public function commande_products(): HasMany
    {
        return $this->hasMany(CommandeProduct::class);
    }
    public function delivrery_driver():BelongsTo
    {
        return $this->belongsTo(DelivreryDriver::class);
    }
}
