<?php

namespace App\Http\Controllers;

use App\Models\Precommande;
use App\Wrappers\Cipher;
use App\Wrappers\FlexPay;
use App\Wrappers\LibPhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Le lien signé mène ici, pas directement à FlexPay : le paiement mobile money
 * exige le numéro du payeur, que la conversation n'a pas de raison de connaître,
 * et une page permet de montrer le récapitulatif avant de débiter.
 */
class PaiementPrecommandeController extends Controller
{
    public function show(string $uid)
    {
        $precommande = $this->trouver($uid);

        if (! $precommande) {
            abort(404);
        }

        // La signature autorise l'accès ; elle ne dit rien de l'état. Une
        // pré-commande déjà payée ou expirée ne doit rien initier.
        if (! $precommande->estValide()) {
            return response()->view('precommande.indisponible', [
                'precommande' => $precommande,
            ], 410);
        }

        return view('precommande.paiement', [
            'precommande' => $precommande->load(['products.product', 'restaurant', 'currency']),
            // L'action du formulaire porte sa propre signature : le jeton CSRF
            // prouve que la requete vient d'une page du site, pas qu'elle porte
            // sur CETTE pre-commande. Sans cela, n'importe quelle session
            // autorise un POST vers n'importe quel uid.
            'action' => URL::temporarySignedRoute(
                'precommande.paiement.initier',
                $precommande->expires_at,
                ['uid' => $uid],
            ),
        ]);
    }

    public function initier(Request $request, string $uid)
    {
        $precommande = $this->trouver($uid);

        if (! $precommande) {
            abort(404);
        }

        if (! $precommande->estValide()) {
            return response()->view('precommande.indisponible', [
                'precommande' => $precommande,
            ], 410);
        }

        $phone = (string) $request->input('phone');

        if (! (new LibPhoneNumber($phone))->checkValidationNumber()) {
            return back()->withErrors(['phone' => 'Numéro de téléphone invalide.']);
        }

        $result = $this->initierFlexPay($precommande, $phone);

        if (! empty($result['code']) && $result['code'] != 0) {
            return back()->withErrors(['phone' => $result['message'] ?? 'Le paiement n\'a pas pu être initié.']);
        }

        return redirect()->away(config('app.url'));
    }

    /**
     * Commune aux deux points d'entrée (lien signé et application) : appelle
     * FlexPay avec le total figé et enregistre la référence de paiement.
     */
    public function initierFlexPay(Precommande $precommande, string $phone): array
    {
        $result = FlexPay::sendData([
            // Le total figé, jamais un montant venu de la requête.
            'amount' => (float) $precommande->total,
            'phone' => $phone,
            'name' => $precommande->recipient_name,
            'email' => $precommande->user?->email,
            'currency' => $precommande->currency?->code ?: 'CDF',
            'reference' => $precommande->refernce,
            'callback_url' => config('flexpay.callback_url'),
            'approve_url' => config('app.url'),
            'cancel_url' => config('app.url'),
            'decline_url' => config('app.url'),
            'language' => 'fr',
            'description' => 'Paiement pré-commande Thalia Eats',
        ], 'mobile');

        if (empty($result['code']) || $result['code'] == 0) {
            $precommande->reference_paiement = $result['orderNumber'] ?? null;
            $precommande->save();
        }

        return $result;
    }

    private function trouver(string $uid): ?Precommande
    {
        $id = Cipher::Decrypt($uid);

        if ($id === false || ! ctype_digit((string) $id)) {
            return null;
        }

        return Precommande::query()->with('user')->find((int) $id);
    }
}
