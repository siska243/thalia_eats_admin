<?php

namespace App\Services\Rental;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\StatusPayement;
use App\Wrappers\FlexPay;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Le retour de FlexPay pour un acompte de location.
 *
 * Chemin distinct de celui des repas, et c'est deliberé : le webhook des
 * commandes est le coeur vivant de la production, avec ses tolerances
 * heritees. Une location est un trafic neuf, sans historique a menager — elle
 * peut donc etre STRICTE la ou l'autre doit rester ouvert.
 *
 * Trois verrous, dans cet ordre :
 *
 * 1. La transaction est relue chez la passerelle. Le corps du webhook vient de
 *    l'appelant : le croire suffirait a confirmer une reservation sans payer.
 * 2. La reference rattachee a la transaction verifiee doit etre celle de la
 *    reservation. Sans ce controle, un orderNumber reellement paye ailleurs
 *    confirmerait n'importe quelle location.
 * 3. Le montant verifie doit etre celui de l'acompte. Sinon, une transaction
 *    d'un dollar reservait une voiture a la journee.
 *
 * Absente, la reference ou le montant font REFUSER — pas passer. C'est la
 * difference avec le chemin repas, ou l'absence est toleree faute d'avoir pu
 * etablir le nom du champ sur du trafic reel.
 */
class DepositWebhook
{
    public function __construct(private readonly BookingWorkflow $workflow) {}

    /** La reference porte-t-elle une location ? */
    public static function concerne(?string $reference): bool
    {
        return is_string($reference) && str_starts_with($reference, 'R-');
    }

    /**
     * @return array{code: string, message: string}
     */
    public function traiter(?string $reference, ?string $orderNumber, array $payload = []): array
    {
        $booking = Booking::query()
            ->with(['user', 'vehicle', 'currency'])
            ->where('reference', $reference)
            ->first();

        if (! $booking) {
            Log::channel('paiement')->warning('location: reference inconnue', [
                'reference' => $reference,
                'orderNumber' => $orderNumber,
            ]);

            return ['code' => 'reference_inconnue', 'message' => 'Référence inconnue'];
        }

        /*
         * FlexPay rejoue ses webhooks. Une reservation deja tranchee — payee
         * et confirmee, ou perdue au profit d'un autre client — ne doit pas
         * etre rejouee : recrediter un acompte deja encaisse fausserait la
         * caisse, et relancer settleDeposit sur une reservation perdue lui
         * rendrait un vehicule attribue entre-temps.
         */
        /*
         * `expired` est accepte ici, alors qu'il est refuse a l'engagement du
         * paiement.
         *
         * Le client a engage son acompte avant l'expiration ; la confirmation
         * arrive apres, parce que mobile money prend parfois plusieurs
         * minutes. Refuser serait encaisser son argent et lui rendre un
         * « deja traite » : il a paye, il a sa voiture. C'est l'engagement qui
         * ferme la porte, pas l'encaissement.
         */
        if (! in_array($booking->status, [Booking::STATUS_PENDING_PAYMENT, Booking::STATUS_EXPIRED], true)) {
            return ['code' => 'deja_traite', 'message' => 'Réservation déjà traitée'];
        }

        try {
            $result = FlexPay::checkPaiement($orderNumber);
        } catch (Throwable $e) {
            Log::channel('paiement')->error('location: verification impossible', [
                'reference' => $reference,
                'orderNumber' => $orderNumber,
                'erreur' => $e->getMessage(),
            ]);

            return ['code' => 'verification_impossible', 'message' => 'Vérification impossible'];
        }

        if (! is_array($result) || (isset($result['code']) && $result['code'] != 0)) {
            $this->echouer($booking, $orderNumber, $result['message'] ?? 'Vérification refusée');

            return ['code' => 'verification_refusee', 'message' => 'Vérification refusée'];
        }

        $reference_verifiee = $result['transaction']['reference'] ?? null;
        $montant_verifie = $result['transaction']['amount'] ?? null;
        $statut_verifie = $result['transaction']['status'] ?? null;

        if ((string) $reference_verifiee !== (string) $booking->reference) {
            Log::channel('paiement')->warning('location: reference de la transaction differente', [
                'annoncee' => $reference,
                'verifiee' => $reference_verifiee,
                'orderNumber' => $orderNumber,
            ]);

            return ['code' => 'reference_incoherente', 'message' => 'Référence incohérente'];
        }

        if ($montant_verifie === null || $this->montantsDivergent($montant_verifie, (float) $booking->deposit)) {
            Log::channel('paiement')->warning('location: montant verifie different de l\'acompte', [
                'reference' => $reference,
                'orderNumber' => $orderNumber,
                'verifie' => $montant_verifie,
                'attendu' => $booking->deposit,
            ]);

            return ['code' => 'montant_incoherent', 'message' => 'Montant incohérent'];
        }

        $status_payement = StatusPayement::query()->where('code', $statut_verifie)->first();

        if (! $status_payement?->is_paid) {
            $this->echouer($booking, $orderNumber, $result['message'] ?? 'Paiement non abouti');

            return ['code' => 'paiement_non_abouti', 'message' => 'Paiement non abouti'];
        }

        /*
         * Le canal et la reference operateur ne sont connus qu'ici : c'est la
         * passerelle qui dit par quel operateur le client a paye. Ils
         * completent la ligne ouverte a l'engagement, ils n'en creent pas une
         * seconde.
         */
        $confirmee = $this->workflow->payDeposit(
            $booking,
            null,
            $orderNumber,
            $payload['phone'] ?? null,
            [
                'status_payement_id' => $status_payement->id,
                'channel' => $payload['channel'] ?? null,
                'provider_reference' => $payload['provider_reference'] ?? null,
            ],
        );

        return $confirmee
            ? ['code' => 'confirmee', 'message' => 'Réservation confirmée']
            : ['code' => 'perdue', 'message' => 'Créneau déjà pris, remboursement à traiter'];
    }

    private function echouer(Booking $booking, ?string $orderNumber, string $raison): void
    {
        $booking->payments()
            ->where('kind', BookingPayment::KIND_DEPOSIT)
            ->where('reference', $orderNumber)
            ->whereNull('paid_at')
            ->latest('id')
            ->first()
            ?->update([
                'failed_at' => now(),
                'failure_reason' => mb_substr($raison, 0, 255),
            ]);
    }

    /** Un centime d'ecart n'est pas une fraude : la tolerance est celle du chemin repas. */
    private function montantsDivergent(mixed $verifie, float $attendu): bool
    {
        return abs((float) $verifie - $attendu) > 0.01;
    }
}
