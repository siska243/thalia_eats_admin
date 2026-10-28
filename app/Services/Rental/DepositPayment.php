<?php

namespace App\Services\Rental;

use App\Exceptions\BookingRefusee;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Vehicle;
use App\Wrappers\FlexPay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Engage le paiement de l'acompte aupres de FlexPay.
 *
 * Une seule implementation pour l'application, le web et le connecteur : le
 * montant, la reference et la devise sont lus sur la reservation, jamais sur
 * la requete. C'est la meme regle que sur les commandes de repas, ou le prix
 * venu du client etait une faille.
 *
 * Rien n'est confirme ici : l'appel a la passerelle ouvre seulement une
 * transaction, et c'est le webhook qui encaisse. Mais le creneau, lui, est
 * retenu des maintenant.
 *
 * C'est le changement de regle. Avant, un vehicule restait disponible tant
 * que l'argent n'etait pas arrive : deux clients pouvaient partir payer le
 * meme vehicule sur le meme creneau, les deux etaient debites, et le perdant
 * passait en `lost` en attendant un remboursement a la main. Un client paie a
 * la fois : le second est refuse avant le debit, avec
 * `BookingRefusee::PAIEMENT_EN_COURS`.
 */
class DepositPayment
{
    /** Les deux seuls litteraux acceptes cote FlexPay. « cart » s'ecrit bien ainsi. */
    public const METHODES = ['mobile', 'cart'];

    public function __construct(
        private readonly VehicleAvailability $availability,
    ) {}

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
        /*
         * `statutEffectif()` et non `status` : une reservation perimee attend
         * encore son acompte en base tant que la tache planifiee n'est pas
         * passee. Engager le paiement la-dessus ferait debiter un client pour
         * une reservation que la creation suivante annulera.
         */
        if ($booking->statutEffectif() === Booking::STATUS_EXPIRED) {
            return ['ok' => false, 'raison' => 'reservation_expiree'];
        }

        if ($booking->status !== Booking::STATUS_PENDING_PAYMENT) {
            return ['ok' => false, 'raison' => 'acompte_non_attendu'];
        }

        if (! $booking->starts_at?->isFuture()) {
            return ['ok' => false, 'raison' => 'creneau_passe'];
        }

        /*
         * La section critique : verifier que le creneau est libre et le
         * retenir ne font qu'un geste, sous verrou de base.
         */
        if (! $this->retenirLeCreneau($booking)) {
            return ['ok' => false, 'raison' => BookingRefusee::PAIEMENT_EN_COURS];
        }

        $retours = [
            'success_url' => $retours['success_url'] ?? null,
            'error_url' => $retours['error_url'] ?? null,
            'cancel_url' => $retours['cancel_url'] ?? null,
        ];

        /*
         * L'appel a la passerelle est hors du verrou, et c'est voulu : une
         * requete HTTP qui pend trente secondes tiendrait la ligne du
         * vehicule verrouillee pendant tout ce temps. Le creneau est deja
         * retenu, personne ne peut le prendre entre-temps.
         *
         * Tout ce qui sort d'ici sans paiement engage relache la retenue,
         * exception comprise : sinon un vehicule resterait bloque jusqu'a son
         * echeance pour une panne reseau.
         */
        try {
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
        } catch (Throwable $e) {
            $this->relacherLeCreneau($booking);

            throw $e;
        }

        if (! empty($result['code']) && $result['code'] != 0) {
            $this->relacherLeCreneau($booking);

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

        /*
         * En carte, l'absence d'URL est un echec silencieux.
         *
         * Le client lit « Le paiement par carte est momentanement
         * indisponible » et personne ne sait pourquoi : la passerelle a
         * repondu sans code d'erreur, mais sans page de saisie non plus. La
         * reponse brute est donc journalisee — c'est la seule trace qui
         * permette de dire si la cle du marchand manque, si le montant est
         * refuse, ou si le format a change.
         */
        if ($method !== 'mobile' && empty($result['url'])) {
            Log::warning('FlexPay carte : aucune URL de paiement rendue', [
                'booking_id' => $booking->id,
                'reference' => $booking->reference,
                'reponse' => $result,
            ]);
        }

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

    /**
     * Retient le creneau pour ce client, ou refuse.
     *
     * Le verrou est pose en base — `lockForUpdate` sur la ligne du vehicule —
     * et non en cache : `CACHE_DRIVER=file`, un verrou de cache ne serait pas
     * partage le jour ou le produit tourne sur deux conteneurs, alors que
     * MySQL est deja la source de verite commune. Aucune dependance de plus.
     *
     * Le verrou porte sur le vehicule, pas sur la reservation : c'est la
     * ressource disputee. Verrouiller la reservation laisserait deux
     * candidats passer cote a cote. Il ne porte pas non plus sur le parc :
     * deux vehicules differents, deux lignes differentes, aucune attente.
     *
     * @return bool Vrai si le creneau est a lui.
     */
    private function retenirLeCreneau(Booking $booking): bool
    {
        return DB::transaction(function () use ($booking) {
            Vehicle::query()->whereKey($booking->vehicle_id)->lockForUpdate()->first();

            $booking->refresh();

            /*
             * Il l'a deja retenu : reessayer son propre paiement — combine
             * eteint, premier essai abandonne — n'est pas un conflit.
             */
            if ($booking->deposit_started_at !== null) {
                return true;
            }

            /*
             * `conflictsFor` en s'ignorant soi-meme : la seule definition de
             * « ce creneau est pris », la meme qu'au catalogue et au webhook.
             */
            $pris = $this->availability
                ->conflictsFor($booking->vehicle, $booking->starts_at, $booking->ends_at, $booking)
                ->exists();

            if ($pris) {
                return false;
            }

            /*
             * `saveQuietly` : la retenue ne change ni le creneau ni le prix,
             * et les crochets du modele n'ont rien a arbitrer ici.
             */
            $booking->forceFill(['deposit_started_at' => now()])->saveQuietly();

            return true;
        });
    }

    /** Rend le creneau aux autres : le paiement n'a pas ete engage. */
    private function relacherLeCreneau(Booking $booking): void
    {
        $booking->forceFill(['deposit_started_at' => null])->saveQuietly();
    }
}
