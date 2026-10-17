<?php

namespace App\Models;

use App\Exceptions\BookingRefusee;
use App\Services\Rental\BookingPricing;
use App\Settings\ExpirationSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Une reservation de vehicule avec chauffeur, sur un creneau horaire. */
class Booking extends Model
{
    use HasFactory;

    /** Creee, acompte non recu. Ne retient PAS le vehicule. */
    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    /** Acompte recu alors que le creneau etait libre : il est ferme. */
    public const STATUS_CONFIRMED = 'confirmed';

    /** Acompte recu, mais quelqu'un avait paye avant : a rembourser. */
    public const STATUS_LOST = 'lost';

    /** Jamais payee, nettoyee. */
    public const STATUS_EXPIRED = 'expired';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Les statuts qui retiennent le creneau a eux seuls.
     *
     * `pending_payment` n'en fait pas partie : une reservation simplement
     * creee ne bloque personne. Elle ne retient le creneau qu'a partir du
     * moment ou son client part payer — voir `scopeHolding()`.
     */
    public const HOLDING_STATUSES = [
        self::STATUS_CONFIRMED,
        self::STATUS_IN_PROGRESS,
    ];

    /**
     * Les statuts d'une course payee et pas encore finie.
     *
     * C'est ce qui ouvre l'acces a l'identite du vehicule et du chauffeur :
     * le client a regle son acompte, il a le droit de savoir quelle voiture
     * vient et qui conduit ; une fois la course terminee, il n'a plus rien a
     * reconnaitre.
     *
     * La liste coincide aujourd'hui avec `HOLDING_STATUSES`, mais ce n'est
     * qu'une coincidence : l'une dit qui retient un creneau, l'autre ce qu'on
     * a le droit de montrer. Les confondre ferait fuiter un chauffeur le jour
     * ou l'une des deux bougera.
     */
    public const COURSE_ENGAGEE = [
        self::STATUS_CONFIRMED,
        self::STATUS_IN_PROGRESS,
    ];

    protected $guarded = [];

    /*
     * La valeur par defaut est declaree ici en plus de la base.
     *
     * Une colonne `default` n'est appliquee que par MySQL, a l'insertion :
     * l'instance en memoire garde `null` jusqu'a une relecture. Tout code qui
     * teste le statut juste apres la creation — et il y en aura — verrait donc
     * une reservation sans statut.
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING_PAYMENT,
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'expires_at' => 'datetime',
        'deposit_started_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'refunded_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'pickup_code_locked_at' => 'datetime',
        'balance_collected_at' => 'datetime',
        'hourly_rate' => 'decimal:2',
        'duration_hours' => 'decimal:2',
        'total' => 'decimal:2',
        'deposit' => 'decimal:2',
        'balance' => 'decimal:2',
        'refund_amount' => 'decimal:2',
    ];

    /**
     * Le code de prise en charge ne sort jamais par accident.
     *
     * Il est retire de toute serialisation par defaut. Les rares endroits qui
     * doivent le montrer — l'ecran client, son QR — le lisent explicitement.
     * Sans ce garde, un `toArray()` dans une reponse destinee au chauffeur le
     * lui livrerait, et il pourrait declarer une course qu'il n'a pas faite.
     */
    protected $hidden = ['pickup_code'];

    protected static function booted(): void
    {
        static::creating(function (self $booking) {
            $booking->reference ??= 'R-'.Str::upper(Str::random(10));

            /*
             * Une reservation qui attend son acompte porte sa date de
             * peremption des sa naissance.
             *
             * Ici et pas dans `BookingCreator` : le back-office cree aussi des
             * reservations, et un futur import en creera d'autres. Un garde
             * place dans un seul chemin de creation ne protege que celui-la,
             * et une reservation sans `expires_at` bloquerait son client sans
             * jamais expirer.
             *
             * Le delai vient de la configuration, partage avec les commandes
             * de repas : la regle est la meme des deux cotes.
             */
            if ($booking->status === self::STATUS_PENDING_PAYMENT) {
                $booking->expires_at ??= now()->addMinutes(self::delaiExpiration());
            }
        });

        /*
         * Une reservation annulee doit le dire des deux facons.
         *
         * Meme piege que sur les commandes : le statut et la date d'annulation
         * sont deux champs que l'administration expose separement. Renseigner
         * la date sans toucher au statut laissait une commande annulee se
         * presenter comme vivante. On rend l'ecart impossible a l'ecriture
         * plutot que de le rattraper dans chaque lecture.
         */
        /*
         * Les bornes de duree sont tenues a l'ecriture, pas seulement par
         * l'API et par le formulaire.
         *
         * C'est le seul point par ou passent tous les chemins de creation —
         * `BookingCreator`, le back-office, un futur import. Un garde de plus
         * haut ne protege que son propre chemin, et une reservation de 24
         * heures facturee a l'heure coute au commerce la difference avec une
         * journee.
         *
         * Le controle ne se declenche que si le creneau ou le mode bougent :
         * changer le statut d'une reservation ancienne, prise avant la regle,
         * ne doit pas devenir impossible.
         */
        static::saving(function (self $booking) {
            $creneauTouche = $booking->isDirty(['starts_at', 'ends_at', 'pricing_mode']);

            if ($creneauTouche && $booking->starts_at && $booking->ends_at) {
                $mode = $booking->pricing_mode ?: BookingPricing::MODE_HOURLY;
                $pricing = app(BookingPricing::class);

                if (! $pricing->dureeDansLesBornes($mode, $booking->starts_at, $booking->ends_at)) {
                    throw new BookingRefusee(
                        BookingRefusee::DUREE_HORS_BORNES,
                        $pricing->messageHorsBornes($mode),
                    );
                }
            }
        });

        static::saving(function (self $booking) {
            if ($booking->cancelled_at && $booking->status !== self::STATUS_CANCELLED) {
                $booking->status = self::STATUS_CANCELLED;
            }

            if ($booking->status === self::STATUS_CANCELLED && ! $booking->cancelled_at) {
                $booking->cancelled_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Le type de course demande, nul sur les reservations d'avant. */
    public function courseType(): BelongsTo
    {
        return $this->belongsTo(CourseType::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function chauffeur(): BelongsTo
    {
        return $this->belongsTo(Chauffeur::class, 'chauffeur_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BookingPayment::class, 'booking_id');
    }

    /**
     * Les reservations qui retiennent encore leur creneau.
     *
     * Deux familles, et une seule definition pour tout le produit — le
     * catalogue, la creation, le webhook d'acompte, l'administration :
     *
     * 1. celles qui sont payees (`confirmed`, `in_progress`) ;
     * 2. celle dont le client est en train de payer. Elle porte
     *    `deposit_started_at` et n'a pas encore atteint son echeance.
     *
     * La seconde famille est nouvelle. Sans elle, deux clients pouvaient
     * partir payer le meme vehicule sur le meme creneau en meme temps : les
     * deux etaient debites, le perdant passait en `lost` et attendait un
     * remboursement manuel. On refuse maintenant le second avant le debit.
     *
     * L'echeance est celle qui existe deja (`expires_at`, reglee dans
     * l'administration) : un seul compte a rebours dans le produit. Quand la
     * reservation expire, le creneau se libere au meme instant, sans attendre
     * la tache planifiee — la comparaison est faite en SQL. Un `expires_at`
     * nul ne retient rien : en SQL la comparaison vaut NULL, donc faux.
     */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->where(fn (Builder $holding) => $holding
            ->whereIn('status', self::HOLDING_STATUSES)
            ->orWhere(fn (Builder $enPaiement) => $enPaiement
                ->where('status', self::STATUS_PENDING_PAYMENT)
                ->whereNotNull('deposit_started_at')
                ->where('expires_at', '>', now())));
    }

    /**
     * Les reservations qui chevauchent un creneau.
     *
     * Deux creneaux se chevauchent si l'un commence avant que l'autre finisse
     * ET finit apres que l'autre commence. Les bornes sont strictes : 10h-15h
     * et 15h-18h se suivent, elles ne se chevauchent pas.
     */
    public function scopeOverlapping(Builder $query, Carbon $startsAt, Carbon $endsAt): Builder
    {
        return $query
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }

    /** Le delai, en minutes, avant qu'une reservation non reglee expire. */
    public static function delaiExpiration(): int
    {
        return ExpirationSettings::delai();
    }

    /**
     * La reservation a-t-elle depasse son delai de paiement ?
     *
     * Calcule a la lecture, sans attendre la tache planifiee. Si le
     * planificateur s'arrete — il l'a deja fait, personne ne s'en apercoit
     * tout de suite — la regle reste juste : le client n'est pas bloque un
     * jour de plus par une reservation qu'il n'a jamais payee.
     *
     * `expires_at` nul veut dire « jamais expiree » : les lignes ecrites avant
     * cette regle n'en portent pas, et les traiter comme expirees ferait
     * disparaitre des reservations vivantes. La migration s'occupe d'elles.
     */
    public function estExpiree(): bool
    {
        return $this->status === self::STATUS_PENDING_PAYMENT
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /**
     * Le statut tel qu'il doit etre lu, expiration comprise.
     *
     * Tout ce qui montre ou teste un statut passe par ici, jamais par
     * `$booking->status` nu : sinon l'ecran client dirait « en attente de
     * paiement » pendant que le serveur refuse l'acompte.
     */
    public function statutEffectif(): string
    {
        return $this->estExpiree() ? self::STATUS_EXPIRED : $this->status;
    }

    /**
     * Ecrit l'expiration en base, si elle est due.
     *
     * Appelee au moment ou le client tente une nouvelle reservation : le
     * laisser passer sans ecrire laisserait une ligne « en attente » eternelle
     * dans l'administration et dans ses propres ecrans.
     */
    public function expirerSiNecessaire(): bool
    {
        if (! $this->estExpiree()) {
            return false;
        }

        $this->status = self::STATUS_EXPIRED;
        $this->save();

        return true;
    }

    /** Les reservations non reglees qui bloquent encore leur client. */
    public function scopeEnAttenteDePaiement(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_PAYMENT);
    }

    /** Les reservations non reglees dont le delai est passe. */
    public function scopeExpirable(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PENDING_PAYMENT)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /** Annulable tant que la course n'a pas commence. */
    public function isCancellable(): bool
    {
        return in_array($this->statutEffectif(), [self::STATUS_PENDING_PAYMENT, self::STATUS_CONFIRMED], true)
            && $this->starts_at?->isFuture();
    }

    public function isPickupCodeLocked(): bool
    {
        return $this->pickup_code_locked_at !== null;
    }

    /**
     * Les releves de position de cette course.
     *
     * Declaree ici, et sur `Commande`, pour qu'un `withCount('releves')`
     * reponde en une requete a la seule question que pose l'administration :
     * « y a-t-il quelque chose a voir sur la carte ? ».
     */
    public function releves(): MorphMany
    {
        return $this->morphMany(TrackOrder::class, 'suivable');
    }
}
