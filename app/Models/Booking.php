<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * Les statuts qui retiennent le creneau.
     *
     * `pending_payment` n'en fait pas partie : le premier qui paie emporte le
     * vehicule, une reservation non reglee ne bloque personne.
     */
    public const HOLDING_STATUSES = [
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
            $booking->reference ??= 'R-' . Str::upper(Str::random(10));
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

    /** Les reservations qui retiennent encore leur creneau. */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->whereIn('status', self::HOLDING_STATUSES);
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

    /** Annulable tant que la course n'a pas commence. */
    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING_PAYMENT, self::STATUS_CONFIRMED], true)
            && $this->starts_at?->isFuture();
    }

    public function isPickupCodeLocked(): bool
    {
        return $this->pickup_code_locked_at !== null;
    }
}
