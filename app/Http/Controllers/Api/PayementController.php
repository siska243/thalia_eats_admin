<?php

namespace App\Http\Controllers\Api;

use App\Events\PayementEvent;
use App\Helpers\CurrentHelpers;
use App\Http\Controllers\Controller;

use App\Http\Resources\CommandeResource;
use App\Models\Commande;
use App\Models\Payement;
use App\Models\StatusPayement;
use App\Wrappers\ApiResponse;
use App\Wrappers\FirebasePushNotification;
use App\Wrappers\FlexPay;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayementController extends Controller
{

    public function webhook(Request $request)
    {
        try {

            $payload = $request->all();

            // Log des données pour tester
            Log::info('Webhook reçu:', $payload);

            $reference = $request->input('reference');
            $amount = $request->input('amount');
            $amountCustomer = $request->input('amountCustomer');
            $channel = $request->input('channel');
            $orderNumber = $request->input('orderNumber');
            $code = $request->input('code');
            $phone = $request->input('phone');
            $provider_reference = $request->input('provider_reference');

            $order = Commande::query()
                ->with('user')
                ->where('refernce', $reference)->first();

            try {
                $result = FlexPay::checkPaiement($orderNumber);
            } catch (\Exception $e) {

                return ApiResponse::SERVER_ERROR($e);
            }


            if ($result['code'] != 0) {

                if ($order?->user?->expo_push_token) {
                    $push = new FirebasePushNotification();
                    $push->sendPushNotification($order?->user->expo_push_token, "Erreur paiement", $result['message']);

                    FirebasePushNotification::sendNotification($order?->user->expo_push_token, "Erreur paiement", $result['message']);

                }

            } else {

                // La reference et l'orderNumber viennent tous deux de l'appelant,
                // et rien ne les liait : un orderNumber reellement paye suffisait
                // a faire marquer payee n'importe quelle commande. On confronte
                // desormais la reference annoncee a celle que la passerelle
                // rattache elle-meme a la transaction verifiee.
                //
                // MODE OUVERT, DELIBERE : si la transaction verifiee ne porte pas
                // de reference, on laisse passer au lieu de refuser. Le nom du
                // champ n'a jamais ete etabli sur une capture reelle de la reponse
                // FlexPay (la preuve vient de CommandeController::swr_check_paiement,
                // qui l'exploite en production, donc indirecte) : refuser sur cette
                // seule hypothese arreterait tous les paiements si elle etait
                // fausse. On le fermera le jour ou le journal « paiement » ci-dessous
                // n'aura signale AUCUNE transaction sans reference sur plusieurs
                // semaines de production — c'est l'observation qui autorisera le
                // strict, pas un avis. Ce n'est donc pas un oubli.
                $reference_verifiee = $result['transaction']['reference'] ?? null;

                // Meme prudence que pour la reference, et pour la meme raison :
                // le nom du champ de montant n'a pas pu etre etabli sur une
                // capture reelle. Absent, on journalise et on laisse passer.
                // Une hypothese fausse sur le nom degrade donc vers le
                // comportement d'aujourd'hui, jamais vers le refus d'un
                // paiement legitime.
                $montant_verifie = $result['transaction']['amount'] ?? null;

                if ($reference_verifiee !== null && (string) $reference_verifiee !== (string) $reference) {
                    Log::channel('paiement')->warning('webhook: reference annoncee differente de la transaction verifiee', [
                        'annoncee' => $reference,
                        'verifiee' => $reference_verifiee,
                        'orderNumber' => $orderNumber,
                    ]);

                    return ApiResponse::BAD_REQUEST(
                        'reference_incoherente',
                        'Oups',
                        'La référence ne correspond pas à la transaction vérifiée.'
                    );
                }

                // Le capteur du mode ouvert. Sans lui, le seul cas ou la
                // verification est purement sautee serait silencieux, et on
                // n'aurait jamais de quoi decider de passer en strict.
                if ($reference_verifiee === null && $order) {
                    Log::channel('paiement')->warning('webhook: transaction verifiee sans reference, coherence non etablie (mode ouvert)', [
                        'reference' => $reference,
                        'orderNumber' => $orderNumber,
                    ]);
                }

                $status = $result['transaction']['status'];

                $status_paiement = StatusPayement::query()->where('code', $status)->first();

                // Une pré-commande porte une référence préfixée « P- », impossible à
                // confondre avec commandes.refernce qui est un entier nu. $order
                // n'est donc null ici que pour une référence qui n'a jamais été une
                // commande : le chemin Commande ci-dessus est rigoureusement inchangé.
                // Une conversion de pre-commande confronte deja le montant sur
                // son propre total, et la commande qui en nait porte ce meme
                // total. Sans ce drapeau, un seul webhook ecrirait DEUX
                // avertissements pour un seul evenement, et qui compte les
                // occurrences du journal compterait double.
                $total_deja_confronte = false;

                if (! $order) {
                    // $result['code'] dit que l'appel de verification a abouti, PAS
                    // que le client a paye. C'est transaction.status, resolu en
                    // StatusPayement.is_paid, qui le dit — et c'est deja ce que
                    // teste le code existant plus bas. Convertir avant ce test
                    // ferait cuisiner un repas non paye, et brulerait la
                    // pre-commande pour le vrai paiement arrivant ensuite.
                    if ($status_paiement?->is_paid) {
                        // Le chemin pre-commande n'a pas encore de trafic de
                        // production : il peut donc echouer ferme. Sans
                        // reference rattachee par la passerelle, rien ne
                        // prouve que cette transaction concerne CETTE
                        // pre-commande — on refuse plutot que de croire
                        // l'appelant sur parole.
                        if ($reference_verifiee === null) {
                            Log::channel('paiement')->warning('webhook: transaction sans reference verifiable, conversion refusee', [
                                'reference' => $reference,
                                'orderNumber' => $orderNumber,
                            ]);

                            return ApiResponse::BAD_REQUEST(
                                'reference_non_verifiable',
                                'Oups',
                                'Cette transaction ne peut pas être rattachée à une pré-commande.'
                            );
                        }

                        // Fix 1 a verrouille l'IDENTITE de la transaction ;
                        // il restait son MONTANT. Sans ce garde, une petite
                        // transaction reellement payee, presentee avec sa
                        // propre reference — donc coherente — faisait
                        // convertir, cuisiner et livrer une pre-commande de
                        // n'importe quel total. Le montant est lu sur la
                        // transaction VERIFIEE, jamais sur la requete : c'est
                        // tout l'interet.
                        $precommande = \App\Models\Precommande::query()
                            ->where('refernce', $reference)
                            ->first();

                        // Le capteur ne parle que si une pre-commande existe
                        // vraiment : une reference inconnue n'a aucun total a
                        // confronter, et journaliser la ferait crier le canal
                        // sur du bruit. Toute la valeur de « paiement » tient
                        // a ce qu'il reste silencieux quand tout va bien.
                        $total_deja_confronte = (bool) $precommande;

                        if ($precommande && $montant_verifie === null) {
                            Log::channel('paiement')->warning('webhook: transaction verifiee sans montant, total non confronte (mode ouvert)', [
                                'reference' => $reference,
                                'orderNumber' => $orderNumber,
                            ]);
                        }

                        if ($precommande && self::montantsDivergent($montant_verifie, $precommande->total)) {
                            Log::channel('paiement')->warning('webhook: montant verifie different du total de la pre-commande, conversion refusee', [
                                'reference' => $reference,
                                'orderNumber' => $orderNumber,
                                'verifie' => $montant_verifie,
                                'attendu' => $precommande->total,
                            ]);

                            return ApiResponse::BAD_REQUEST(
                                'montant_incoherent',
                                'Oups',
                                'Le montant payé ne correspond pas à celui de la pré-commande.'
                            );
                        }

                        $order = app(\App\Services\ConversionPrecommande::class)
                            ->convertirSiPossible($reference);
                    }

                    if (! $order) {
                        Log::warning('webhook: aucune commande pour cette reference', [
                            'reference' => $reference,
                            'paye' => (bool) $status_paiement?->is_paid,
                        ]);

                        return ApiResponse::GET_DATA(['message' => 'Référence inconnue']);
                    }
                }

                Payement::query()->updateOrCreate([
                    'commande_id' => $order?->id,
                    'phone' => preg_replace('/[\s+]/', '', $phone),
                    'channel' => "MPESA",
                ], [
                    'code' => $result['code'],
                    'commande_id' => $order->id,
                    'phone' => preg_replace('/[\s+]/', '', $phone),
                    'channel' => $channel,
                    'status_payement_id' => $status_paiement?->id,
                    'amount' => $amount,
                    'amount_customer' => $amountCustomer,
                    "provider_reference" => $provider_reference
                ]);

                if ($status_paiement->is_paid) {
                    // Chemin de production vivant : on OBSERVE, on ne refuse
                    // pas. Un ecart de montant peut avoir des causes
                    // legitimes qu'on ne connait pas encore (arrondis,
                    // devise, frais operateur) et refuser ici bloquerait des
                    // paiements reels. C'est ce journal qui dira, avec des
                    // semaines de production, si un refus est tenable.
                    if ($total_deja_confronte) {
                        // Rien : la branche pre-commande vient de le faire sur
                        // le meme montant. Un evenement, un avertissement.
                    } elseif ($montant_verifie === null) {
                        Log::channel('paiement')->warning('webhook: transaction verifiee sans montant, prix de la commande non confronte (mode ouvert)', [
                            'reference' => $reference,
                            'orderNumber' => $orderNumber,
                        ]);
                    } elseif (self::montantsDivergent($montant_verifie, $order->global_price)) {
                        Log::channel('paiement')->warning('webhook: montant verifie different du prix de la commande', [
                            'reference' => $reference,
                            'orderNumber' => $orderNumber,
                            'verifie' => $montant_verifie,
                            'attendu' => $order->global_price,
                        ]);
                    }

                    $order->status_id = 2;
                    $order->reference_paiement = $provider_reference;
                    //envoyer la commande au restaurateur
                    $order->paied_at = now()->format("Y-m-d H:i:s");
                    $order->save();

                    $user = CurrentHelpers::getUserByOrder($order);

                    if ($user) {
                        $body = [
                            'action' => 'paiement-check',
                            'status' => $status_paiement,
                        ];

                        if ($user->expo_push_token) {
                            $push = new FirebasePushNotification();
                            $push->sendPushNotification($user->expo_push_token, "Nouvelle commande", json_encode($body));

                            FirebasePushNotification::sendNotification($user->expo_push_token, "Thalia eats commande", "Nouvelle commande");
                        }

                    }
                }

                $body = [
                    'action' => 'paiement-check',
                    'status' => $status_paiement,
                ];

                $payement = Payement::query()->where('commande_id', $order?->id)
                    ->whereNotNull('webhook_sse_url')
                    ->first();

                if ($payement) {
                    $response = Http::post($payement->webhook_sse_url, [
                        'payload' => json_encode($body)
                    ]);

                }

                if ($order?->user?->expo_push_token) {

                    $push = new FirebasePushNotification();

                    $push->sendPushNotification($order?->user->expo_push_token, $result['message'], json_encode($body));

                    FirebasePushNotification::sendNotification($order?->user->expo_push_token, "État commande thalia", $result['message']);
                }


            }

            //event(new PayementEvent($orderNumber,$reference));

            return ApiResponse::SUCCESS_DATA('');
        } catch (Exception $e) {

            Log::info('Webhook error reçu:', $e);
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    /**
     * Compare deux montants avec une tolerance d'UN CENTIME.
     *
     * L'egalite stricte sur des flottants ferait crier le garde sur des
     * paiements parfaitement valides : le projet a deja un precedent de
     * divergence d'un centime entre round() cote PHP et toFixed(2) cote
     * client. Un ecart superieur au centime, lui, n'est jamais un arrondi.
     *
     * Un montant verifie absent ne diverge de rien : c'est le mode ouvert,
     * l'appelant n'en tire aucun avantage puisque le montant compare vient
     * de la passerelle, jamais de sa requete.
     */
    private static function montantsDivergent($verifie, $attendu): bool
    {
        if ($verifie === null) {
            return false;
        }

        // L'ecart est arrondi au centime AVANT d'etre compare : sans cela,
        // 5500.00 - 5499.99 vaut 0.010000000000218 en binaire, donc « > 0.01 »,
        // et le garde refuserait l'ecart d'un centime qu'il est cense tolerer.
        return round(abs((float) $verifie - (float) $attendu), 2) > 0.01;
    }


}
