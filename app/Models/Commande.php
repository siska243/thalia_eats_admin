<?php

namespace App\Models;

use App\Settings\ExpirationSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

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

    /**
     * Les statuts d'une commande qui attend encore son reglement.
     *
     * Cette liste etait ecrite en nombres nus, `whereIn('status_id', [1, 5])`,
     * a chaque endroit qui en avait besoin. Nommee une fois, elle sert au
     * crochet d'ecriture, aux scopes et a la lecture du statut effectif.
     */
    public const STATUTS_NON_REGLES = [
        self::STATUT_EN_ATTENTE,
        self::STATUT_ATTENTE_PAIEMENT,
    ];

    /**
     * Les statuts ou le client compose encore son panier.
     *
     * Le statut 5 en est exclu, et c'est tout l'objet de cette constante : une
     * commande envoyee au paiement est un montant que la passerelle tient
     * deja. La laisser modifiable, c'est permettre de payer un panier et d'en
     * recevoir un autre, ou de payer la livraison de Gombe pour se faire
     * livrer a Ngaliema.
     *
     * Nommee ici plutot que testee statut par statut dans chaque route :
     * `store` avait ete ferme en comparant status_id a 5 sur place, et les
     * trois routes de panier etaient restees ouvertes faute d'avoir la meme
     * phrase sous la main.
     */
    public const STATUTS_MODIFIABLES = [
        self::STATUT_EN_ATTENTE,
    ];

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

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Le delai, en minutes, avant qu'une commande non reglee expire.
     *
     * Il vient des reglages d'administration, partage avec la location : la
     * regle annoncee au client est la meme des deux cotes, et une seconde
     * copie divergerait au premier ajustement commercial.
     */
    public static function delaiExpiration(): int
    {
        return ExpirationSettings::delai();
    }

    /**
     * La commande a-t-elle depasse son delai de paiement ?
     *
     * Calcule a la lecture, sans attendre la tache planifiee : si le
     * planificateur s'arrete — il l'a deja fait — la regle reste juste.
     *
     * `expires_at` nul vaut « pas d'echeance » : les lignes ecrites avant
     * cette regle n'en portent pas, et les traiter comme perimees ferait
     * disparaitre des commandes vivantes.
     */
    public function estExpiree(): bool
    {
        return in_array((int) $this->status_id, self::STATUTS_NON_REGLES, true)
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /**
     * Le statut tel qu'il doit etre lu, annulation et expiration comprises.
     *
     * Meme definition unique que `Booking::statutEffectif()`. Tout ce qui
     * montre ou teste un statut passe par ici, jamais par `$commande->status_id`
     * nu : sinon l'ecran dirait « en attente de paiement » pendant que le
     * serveur refuse le paiement.
     */
    public function statutEffectif(): int
    {
        if ($this->cancel_at !== null || (int) $this->status_id === self::STATUT_ANNULEE) {
            return self::STATUT_ANNULEE;
        }

        return $this->estExpiree() ? self::STATUT_ANNULEE : (int) $this->status_id;
    }

    /**
     * Le `Status` a montrer, derive de `statutEffectif()`.
     *
     * Rend la relation chargee quand le statut reel est celui de la base, et
     * le statut d'annulation quand la commande est expiree : l'ecran lit un
     * objet `Status` complet dans les deux cas, avec son libelle et sa
     * couleur, sans avoir a connaitre nos numeros.
     */
    public function statutLisible(): ?Status
    {
        $effectif = $this->statutEffectif();

        if ($effectif === (int) $this->status_id) {
            return $this->status;
        }

        return Status::query()->find($effectif) ?? $this->status;
    }

    /**
     * La commande attend-elle encore un reglement ?
     *
     * Une seule question, posee au statut effectif : ni annulee, ni perimee,
     * et dans l'un des statuts non regles. Tout ce qui montre une echeance
     * passe par ici — une commande livree ou deja perimee qui sortirait avec
     * une date ferait decompter l'application a l'envers.
     */
    public function attendUnReglement(): bool
    {
        return in_array($this->statutEffectif(), self::STATUTS_NON_REGLES, true);
    }

    /**
     * Le client peut-il encore toucher a cette commande ?
     *
     * Posee au statut effectif, comme `attendUnReglement()` : une commande
     * annulee ou perimee n'est pas modifiable non plus, meme si sa colonne
     * status_id est restee a 1. Les lignes heritees portent exactement cet
     * ecart.
     */
    public function estModifiable(): bool
    {
        return in_array($this->statutEffectif(), self::STATUTS_MODIFIABLES, true);
    }

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
        /*
         * Une commande non reglee porte toujours sa date de peremption.
         *
         * Elle est repoussee a chaque enregistrement tant que la commande est
         * en attente (1) ou en attente de paiement (5) : le delai se compte
         * depuis la derniere activite du client, pas depuis la creation du
         * panier. Le compter depuis `created_at` ferait expirer le panier d'un
         * client qui prend le temps de choisir, et pire, ferait naitre deja
         * perimee la commande qu'il envoie au paiement apres vingt minutes de
         * selection — il serait debite pour une commande annulee.
         *
         * Ici et pas dans le controleur : la creation passe par `store`, par
         * `valide`, par l'administration et par le connecteur. Un garde par
         * chemin, c'est le motif qui a deja produit deux bugs sur ce depot.
         */
        static::saving(function (self $commande) {
            if (in_array((int) $commande->status_id, self::STATUTS_NON_REGLES, true)) {
                $commande->expires_at = now()->addMinutes(self::delaiExpiration());
            }
        });

        static::saving(function (self $commande) {
            if ($commande->cancel_at && (int) $commande->status_id !== self::STATUT_ANNULEE) {
                $commande->status_id = self::STATUT_ANNULEE;
            }

            if ((int) $commande->status_id === self::STATUT_ANNULEE && ! $commande->cancel_at) {
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
        return $query
            ->whereIn('status_id', self::STATUTS_NON_REGLES)
            ->nonAnnulee()
            ->nonExpiree();
    }

    /**
     * Les commandes dont le delai de paiement n'est pas ecoule.
     *
     * L'expiration est calculee a la lecture, pas seulement ecrite par la
     * tache planifiee. Si le planificateur s'arrete — personne ne s'en
     * apercoit le jour meme — la regle reste juste : le client n'est pas
     * bloque indefiniment par une commande qu'il n'a jamais payee.
     *
     * `expires_at` nul vaut « pas d'echeance » : les lignes ecrites avant
     * cette regle n'en portent pas, et les traiter comme expirees ferait
     * disparaitre d'un coup des commandes vivantes. La migration s'occupe des
     * anciennes.
     *
     * Le statut compte autant que la date, exactement comme `estExpiree()` :
     * seule une commande qui attend encore son reglement peut perimer. Sans
     * cette condition, une commande deja payee qui aurait garde son echeance
     * disparaitrait des ecrans.
     */
    public function scopeNonExpiree(Builder $query): Builder
    {
        return $query->whereNot(function (Builder $q) {
            $q->whereIn('status_id', self::STATUTS_NON_REGLES)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now());
        });
    }

    /** Les commandes non reglees dont le delai de paiement est depasse. */
    public function scopeExpiree(Builder $query): Builder
    {
        return $query
            ->whereIn('status_id', self::STATUTS_NON_REGLES)
            ->nonAnnulee()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Annule sur-le-champ les commandes perimees d'un client.
     *
     * Appelee avant toute tentative de nouvelle commande : sans elle, le
     * client passerait bien sa commande — la lecture ignore deja les
     * perimees — mais l'ancienne resterait affichee « en attente » dans ses
     * ecrans et dans l'administration jusqu'au prochain passage de la tache.
     *
     * L'ecriture passe par `save()` et non par un `update()` en masse : le
     * crochet du modele pose `cancel_at` en meme temps que le statut, et une
     * mise a jour globale recreerait l'incoherence qu'il existe pour empecher.
     *
     * @return int Le nombre de commandes annulees.
     */
    public static function expirerPour(int $userId): int
    {
        $annulees = 0;

        static::query()->where('user_id', $userId)->expiree()->get()
            ->each(function (self $commande) use (&$annulees) {
                $commande->status_id = self::STATUT_ANNULEE;
                $commande->save();
                $annulees++;
            });

        return $annulees;
    }

    /**
     * Reserve l'unique commande ouverte d'un client, sous verrou de ligne.
     *
     * « Une commande non reglee a la fois » n'etait qu'une lecture suivie
     * d'une ecriture, sans rien entre les deux : deux appels simultanes a
     * `valide()` lisaient tous deux « aucune commande en attente » et creaient
     * chacun la leur. Le client repartait avec deux references et deux
     * montants engages aupres de FlexPay. Un double-clic sur « Payer » suffit.
     *
     * Le verrou porte sur la ligne du client et non sur la commande : c'est
     * lui la ressource disputee, et il n'y a justement pas encore de commande
     * a verrouiller. Meme raisonnement que `BookingConfirmation`, ou le verrou
     * porte sur le vehicule et non sur la reservation.
     *
     * L'appel sortant vers la passerelle reste en dehors : une transaction ne
     * tient pas une ligne pendant une requete HTTP.
     *
     * @return self|null La commande deja ouverte qui s'oppose a la
     *                   reservation, ou null si celle-ci a eu lieu.
     */
    public static function reserverPour(User $user, self $commande): ?self
    {
        return DB::transaction(function () use ($user, $commande) {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            // Relue sous verrou : la lecture faite avant la section critique
            // ne prouve plus rien une fois qu'on y entre.
            $existante = static::query()->nonReglee()->where('user_id', $user->getKey())->first();

            if ($existante) {
                return $existante;
            }

            $commande->save();

            return null;
        });
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function product(): HasMany
    {
        return $this->hasMany(CommandeProduct::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /**
     * La somme des lignes, hors livraison et service.
     *
     * Definie une seule fois : trois endroits la recalculaient chacun a sa
     * facon, et c'est ainsi qu'une commande finit par porter deux montants.
     */
    public function sousTotal(): float
    {
        return round(
            (float) $this->loadMissing('product')->product->sum(
                fn (CommandeProduct $ligne) => (float) $ligne->price * (float) $ligne->quantity
            ),
            2
        );
    }

    /**
     * Le montant affiche doit etre le montant paye.
     *
     * `global_price` ne portait que les produits tant que la commande n'etait
     * pas reglee : le client lisait « Total » sur son ecran — c'est le libelle
     * employe par le site comme par le telephone — et payait autre chose au
     * moment de valider. Livraison et service y sont donc compris des la
     * creation.
     */
    public function recalculerTotal(): void
    {
        $this->global_price = round(
            $this->sousTotal() + (float) $this->price_delivery + (float) $this->price_service,
            2
        );
    }

    public function town(): BelongsTo
    {
        return $this->belongsTo(Town::class);
    }

    public function commande_products(): HasMany
    {
        return $this->hasMany(CommandeProduct::class);
    }

    public function delivrery_driver(): BelongsTo
    {
        return $this->belongsTo(DelivreryDriver::class);
    }

    /** Les releves de position de cette livraison. Voir `Booking::releves()`. */
    public function releves(): MorphMany
    {
        return $this->morphMany(TrackOrder::class, 'suivable');
    }
}
