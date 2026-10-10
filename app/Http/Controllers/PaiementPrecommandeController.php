<?php

namespace App\Http\Controllers;

use Throwable;
use App\Models\Precommande;
use App\Services\PaiementPrecommande;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * La page Blade de paiement d'une pre-commande.
 *
 * CONSERVEE POUR LES LIENS DEJA EMIS. Le lien qu'un client recoit desormais
 * mene au site Next.js (voir Api\LienPaiementPrecommandeController), comme le
 * veut la regle d'architecture du projet : « Pas de rendu Blade cote produit ».
 * Mais des liens valables douze heures circulent peut-etre encore ; les casser
 * laisserait quelqu'un avec un repas commande et aucun moyen de payer. Ces
 * deux routes pourront partir une fois cette fenetre ecoulee.
 *
 * Toute la logique metier vit dans App\Services\PaiementPrecommande : les deux
 * chemins ne doivent jamais diverger.
 */
class PaiementPrecommandeController extends Controller
{
    public function __construct(private readonly PaiementPrecommande $paiements) {}

    public function show(string $uid)
    {
        $precommande = $this->paiements->trouver($uid);

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
            'precommande' => $precommande->load(['products.product', 'restaurant', 'currency', 'town']),
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
        $precommande = $this->paiements->trouver($uid);

        if (! $precommande) {
            abort(404);
        }

        if (! $precommande->estValide()) {
            return response()->view('precommande.indisponible', [
                'precommande' => $precommande,
            ], 410);
        }

        // Figer les coordonnées si elles ne le sont pas encore. Si elles le
        // sont déjà, ce que le formulaire envoie est purement ignoré : un lien
        // peut avoir été transféré, et celui qui l'a ne doit pas pouvoir
        // détourner une livraison déjà renseignée.
        if (! $precommande->coordonneesCompletes()) {
            $this->paiements->figerCoordonnees($precommande, $request->validate(
                $this->paiements->reglesCoordonnees(),
                $this->paiements->messagesCoordonnees(),
            ));
        }

        $method = (string) $request->input('method', 'mobile');

        if (! $this->paiements->methodeConnue($method)) {
            return back()->withErrors(['method' => 'Choisissez un moyen de paiement.'])->withInput();
        }

        if (! $this->paiements->minimumCarteAtteint($precommande, $method)) {
            return back()->withErrors([
                'method' => PaiementPrecommande::MESSAGE_MINIMUM_CARTE,
            ])->withInput();
        }

        // Le téléphone du payeur n'existe qu'en mobile money : la carte est
        // débitée sur la page de la passerelle. Le numéro du destinataire, lui,
        // reste obligatoire dans les deux cas — c'est celui du livreur.
        $phone = '';

        if ($method === 'mobile') {
            $phone = $this->paiements->numeroDuPayeur(
                $precommande,
                $request->boolean('meme_numero'),
                (string) $request->input('phone'),
            );

            if (! $this->paiements->numeroValide($phone)) {
                return back()->withErrors(['phone' => 'Numéro de téléphone invalide.'])->withInput();
            }
        }

        $result = $this->paiements->initierFlexPay($precommande, $phone, $method);

        if (! empty($result['code']) && $result['code'] != 0) {
            return back()->withErrors([
                'phone' => $result['message'] ?? 'Le paiement n\'a pas pu être initié.',
            ])->withInput();
        }

        if ($method === 'cart') {
            // La passerelle carte renvoie l'URL de sa page de saisie dans
            // « url ». Sans elle, mieux vaut ramener le client au formulaire
            // que le rediriger vers le vide.
            if (empty($result['url'])) {
                return back()->withErrors([
                    'method' => 'Le paiement par carte est momentanément indisponible, réessayez.',
                ])->withInput();
            }

            return redirect()->away($result['url']);
        }

        // Mobile money : le débit se confirme sur le combiné. L'application
        // laisse aujourd'hui le client sans nouvelle à ce moment précis ; on
        // lui dit au moins quoi faire.
        return response()->view('precommande.confirmation', [
            'precommande' => $precommande,
            'phone' => $phone,
        ]);
    }

    /**
     * Conservee pour Api\PrecommandeController::payer(), qui l'appelait via le
     * conteneur. Elle ne fait plus que deleguer au service.
     *
     * @return array<string, mixed>
     */
    public function initierFlexPay(Precommande $precommande, string $phone, string $method): array
    {
        $result = FlexPay::sendData([
            // Le total figé, jamais un montant venu de la requête.
            'amount' => (float) $precommande->total,
            // Chaîne vide en carte : c'est ce que fait l'application.
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
        ], $method);

        if (empty($result['code']) || $result['code'] == 0) {
            $precommande->reference_paiement = $result['orderNumber'] ?? null;
            $precommande->save();
        }

        return $result;
    }

    /**
     * Écrit les coordonnées champ par champ, jamais depuis le tableau de
     * requête : Model::unguard() est global, les règles de validation sont la
     * seule liste blanche.
     */
    private function figerCoordonnees(Request $request, Precommande $precommande): void
    {
        $donnees = $request->validate([
            'adresse' => ['required', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'number_street' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:255'],
            'recipient_name' => ['required', 'string', 'max:120'],
            'recipient_phone' => ['required', 'string', 'max:30'],
        ], [
            'adresse.required' => 'L\'adresse de livraison est obligatoire.',
            'recipient_name.required' => 'Le nom de la personne à livrer est obligatoire.',
            'recipient_phone.required' => 'Le numéro que le livreur appellera est obligatoire.',
        ]);

        // La commune n'est jamais reprise du formulaire : elle a servi à
        // choisir la tranche de livraison, donc le total figé. L'accepter
        // laisserait payer un tarif du centre pour une livraison en périphérie.
        $precommande->adresse_delivery = $donnees['adresse'];
        $precommande->street = $donnees['street'] ?? null;
        $precommande->number_street = $donnees['number_street'] ?? null;
        $precommande->reference_adresse = $donnees['reference'] ?? null;
        $precommande->recipient_name = $donnees['recipient_name'];
        $precommande->recipient_phone = $donnees['recipient_phone'];
        $precommande->save();
    }

    /**
     * Le garde contre la TypeError de LibPhoneNumber vit désormais dans le
     * wrapper lui-même, seule définition de « ce numéro est valide » : les
     * trois autres appelants en bénéficient, dont le chemin de commande de
     * production qui rendait un 500 sur un numéro mal tapé. On ne garde ici
     * qu'un nom lisible au point d'appel.
     */
    private function numeroValide(string $phone): bool
    {
        try {
            return (new LibPhoneNumber($phone))->checkValidationNumber();
        } catch (Throwable) {
            return false;
        }
    }

    private function trouver(string $uid): ?Precommande
    {
        $id = Cipher::Decrypt($uid);

        if ($id === false || ! ctype_digit((string) $id)) {
            return null;
        }

        return Precommande::query()->with(['user', 'town'])->find((int) $id);
    }
}
