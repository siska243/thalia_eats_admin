<?php

namespace App\Services\Rental;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Wrappers\FlexPay;
use Illuminate\Support\Facades\Log;

/**
 * Engage le paiement de l'acompte aupres de FlexPay.
 *
 * Une seule implementation pour l'application, le web et le connecteur : le
 * montant, la reference et la devise sont lus sur la reservation, jamais sur
 * la requete. C'est la meme regle que sur les commandes de repas, ou le prix
 * venu du client etait une faille.
 *
 * Rien n'est confirme ici. L'appel a la passerelle ouvre seulement une
 * transaction ; c'est le webhook qui decidera, sous verrou, si le creneau est
 * encore libre. Le vehicule reste donc disponible tant que l'argent n'est pas
 * arrive — c'est la regle posee des le depart : le premier qui paie gagne.
 */
class DepositPayment
{
    /** Les deux seuls litteraux acceptes cote FlexPay. « cart » s'ecrit bien ainsi. */
    public const METHODES = ['mobile', 'cart'];

    /**
     * @return array{ok: bool, raison?: string, message?: string, url?: string, orderNumber?: string}
     */
    /**
     * @param  array{success_url?: ?string, error_url?: ?string, cancel_url?: ?string}  $retours
     *                                                                                            Les adresses de retour dans l'application. Absentes, on retombe sur le
     *                                                                                            site, ce qui reste le comportement d'un appelant qui ne les envoie pas.
     */
    public function initier(Booking $booking, string $method, string $phone, array $retours = []): array
    {
        if ($booking->status !== Booking::STATUS_PENDING_PAYMENT) {
            return ['ok' => false, 'raison' => 'acompte_non_attendu'];
        }

        if (! $booking->starts_at?->isFuture()) {
            return ['ok' => false, 'raison' => 'creneau_passe'];
        }

        $retours = [
            'success_url' => $retours['success_url'] ?? null,
            'error_url' => $retours['error_url'] ?? null,
            'cancel_url' => $retours['cancel_url'] ?? null,
        ];

        $result = FlexPay::sendData([
            // Le montant fige, jamais celui de la requete.
            'amount' => (float) $booking->deposit,
            'phone' => $phone,
            'name' => $booking->user?->full_name ?: 'Client Thalia Eats',
            'email' => $booking->user?->email,
            'currency' => $booking->currency?->code ?: 'CDF',
            // La reference porte le prefixe « R- » : c'est elle qui, au
            // retour, distingue une location d'une commande de repas.
            'reference' => $booking->reference,
            // Le client ne decide pas ou son paiement est confirme : cette
            // adresse est appelee de serveur a serveur.
            'callback_url' => config('flexpay.callback_url'),
            'approve_url' => $retours['success_url'] ?: config('app.url'),
            'cancel_url' => $retours['cancel_url'] ?: config('app.url'),
            'decline_url' => $retours['error_url'] ?: config('app.url'),
            'language' => 'fr',
            'description' => 'Acompte location Thalia Eats',
        ], $method);

        if (! empty($result['code']) && $result['code'] != 0) {
            return [
                'ok' => false,
                'raison' => 'paiement_refuse',
                'message' => $result['message'] ?? null,
            ];
        }

        $orderNumber = $result['orderNumber'] ?? null;

        /*
         * La ligne est ecrite sans `paid_at` : une tentative n'est pas un
         * encaissement. Sans elle, un paiement engage puis jamais confirme ne
         * laisserait aucune trace, et personne ne saurait qu'un client a ete
         * debite sans obtenir sa voiture.
         */
        $this->tracer($booking, $orderNumber, $method, $phone);

        return [
            'ok' => true,
            'orderNumber' => $orderNumber,
            // La passerelle carte renvoie l'URL de sa page de saisie.
            'url' => $result['url'] ?? null,
        ];
    }

    private function tracer(Booking $booking, ?string $orderNumber, string $method, string $phone): void
    {
        /*
         * Ni moyen ni canal a l'engagement : on ne sait pas encore par quel
         * operateur le client paiera. FlexPay le dit au retour, dans
         * `channel` ; les renseigner ici serait une supposition ecrite en
         * base, et le rapprochement comptable la relirait comme un fait.
         */
        BookingPayment::query()->updateOrCreate([
            'booking_id' => $booking->id,
            'kind' => BookingPayment::KIND_DEPOSIT,
            'paid_at' => null,
        ], [
            'amount' => (float) $booking->deposit,
            'currency_id' => $booking->currency_id,
            'reference' => $orderNumber,
            'phone' => $phone !== '' ? $phone : null,
            'status_payement_id' => null,
        ]);

        Log::channel('paiement')->info('location: acompte engage', [
            'booking' => $booking->reference,
            'orderNumber' => $orderNumber,
            'method' => $method,
        ]);
    }
}
