<?php

namespace App\Services\Rental;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Chauffeur;
use App\Models\PaimentMethod;
use App\Models\StatusPayement;
use App\Settings\RentalSettings;
use Illuminate\Support\Facades\DB;

/**
 * Le cycle de vie d'une reservation.
 *
 * Une seule implementation des transitions, partagee par l'administration
 * aujourd'hui et par l'API demain. Les ecrire deux fois ferait diverger ce que
 * le back-office enregistre de ce que le client voit.
 *
 * Le service ne juge pas qui a le droit : l'administrateur peut tout faire.
 * Il garantit seulement la coherence — qu'un encaissement laisse une trace,
 * qu'une annulation calcule ce qui est du, qu'un statut ne parte jamais seul.
 */
class BookingWorkflow
{
    public function __construct(
        private readonly BookingConfirmation $confirmation,
        private readonly RentalSettings $settings,
    ) {
    }

    /**
     * L'acompte est encaisse.
     *
     * Enregistre le paiement, puis laisse `BookingConfirmation` decider sous
     * verrou si le creneau est encore libre. Le client peut avoir paye en
     * ligne ou au comptoir : le moyen est passe en parametre.
     *
     * @return bool Vrai si la reservation est confirmee, faux si elle est perdue.
     */
    public function payDeposit(
        Booking $booking,
        ?int $paimentMethodId = null,
        ?string $reference = null,
        ?string $phone = null,
    ): bool {
        $amount = (float) $booking->deposit;

        $this->recordPayment($booking, BookingPayment::KIND_DEPOSIT, $amount, [
            'paiment_method_id' => $paimentMethodId,
            'reference' => $reference,
            'phone' => $phone,
            'paid_at' => now(),
        ]);

        return $this->confirmation->settleDeposit($booking, $amount);
    }

    /** Le chauffeur prend la course en charge. */
    public function assignChauffeur(Booking $booking, Chauffeur $chauffeur): void
    {
        $booking->update(['chauffeur_id' => $chauffeur->id]);
    }

    /**
     * Le client est monte : la course commence.
     *
     * `picked_up_by` recoit le chauffeur affecte — c'est lui qui a constate la
     * prise en charge, et c'est ce nom qu'on relira en cas de litige.
     */
    public function startRide(Booking $booking): void
    {
        $booking->update([
            'status' => Booking::STATUS_IN_PROGRESS,
            'picked_up_at' => $booking->picked_up_at ?? now(),
            'picked_up_by' => $booking->picked_up_by ?? $booking->chauffeur_id,
        ]);
    }

    /**
     * Le solde est remis en especes au chauffeur, la course se termine.
     *
     * L'encaissement laisse une ligne de paiement : sans elle, l'argent remis
     * n'a aucune trace, et le rapprochement de fin de journee est impossible.
     */
    public function collectBalance(Booking $booking, ?Chauffeur $collectedBy = null): void
    {
        $chauffeur = $collectedBy ?? $booking->chauffeur;

        $this->recordPayment($booking, BookingPayment::KIND_BALANCE, (float) $booking->balance, [
            'paiment_method_id' => PaimentMethod::query()->where('slug', 'especes')->value('id'),
            'recorded_by' => $chauffeur?->id,
            'paid_at' => now(),
        ]);

        $booking->update([
            'status' => Booking::STATUS_COMPLETED,
            'completed_at' => now(),
            'balance_collected_at' => now(),
            'balance_collected_by' => $chauffeur?->id,
        ]);
    }

    /**
     * La reservation est annulee.
     *
     * Le remboursement du est calcule sur ce qui a REELLEMENT ete paye, pas
     * sur l'acompte theorique : un client qui n'a jamais rien verse n'a rien a
     * se voir rendre. Le virement, lui, reste manuel.
     */
    public function cancel(Booking $booking, ?string $reason = null): void
    {
        $paid = (float) $booking->payments()->whereNotNull('paid_at')->sum('amount');

        $booking->update([
            'status' => Booking::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            'refund_amount' => $paid > 0
                ? round($paid * $this->settings->refund_percentage / 100, 2)
                : null,
        ]);
    }

    /** Le virement a ete fait hors de l'application. */
    public function markRefunded(Booking $booking): void
    {
        $booking->update(['refunded_at' => now()]);
    }

    /** Le code de prise en charge est debloque par un administrateur. */
    public function unlockPickupCode(Booking $booking): void
    {
        $booking->update([
            'pickup_code_locked_at' => null,
            'pickup_code_attempts' => 0,
        ]);
    }

    /**
     * Enregistre une tentative de paiement.
     *
     * Le statut « paye » est celui du referentiel partage avec les commandes
     * de repas : deux tables de statuts auraient diverge.
     */
    public function recordPayment(Booking $booking, string $kind, float $amount, array $attributes = []): BookingPayment
    {
        return DB::transaction(fn () => $booking->payments()->create(array_merge([
            'kind' => $kind,
            'amount' => $amount,
            'currency_id' => $booking->currency_id,
            'status_payement_id' => StatusPayement::query()->where('is_paid', true)->value('id'),
        ], $attributes)));
    }
}
