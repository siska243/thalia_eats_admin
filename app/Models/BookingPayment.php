<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une tentative de paiement sur une reservation.
 *
 * Une ligne par tentative : un client qui se trompe de numero, reessaie et
 * reussit en laisse trois. C'est ce qu'on veut lire quand un paiement est
 * conteste.
 */
class BookingPayment extends Model
{
    use HasFactory;

    /** Les 10 % regles en ligne au moment de reserver. */
    public const KIND_DEPOSIT = 'deposit';

    /** Les 90 % restants, remis en especes au chauffeur. */
    public const KIND_BALANCE = 'balance';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function paimentMethod(): BelongsTo
    {
        return $this->belongsTo(PaimentMethod::class, 'paiment_method_id');
    }

    public function statusPayement(): BelongsTo
    {
        return $this->belongsTo(StatusPayement::class, 'status_payement_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /** Le chauffeur qui declare avoir encaisse le solde. Nul en ligne. */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Chauffeur::class, 'recorded_by');
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }
}
