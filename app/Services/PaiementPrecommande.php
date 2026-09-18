<?php

namespace App\Services;

use App\Models\Precommande;
use App\Wrappers\Cipher;
use App\Wrappers\FlexPay;
use App\Wrappers\LibPhoneNumber;

/**
 * Les regles du paiement d'une pre-commande, extraites du controleur Blade.
 *
 * Deux chemins menent desormais au meme paiement : la page Blade historique,
 * conservee pour les liens deja chez des clients, et le point d'entree d'API
 * qui sert la page du site. Recopier le controle du minimum carte, la liste
 * blanche des coordonnees ou le choix du numero du payeur dans le second
 * aurait fabrique deux definitions de la meme regle — et ce projet a deja paye
 * le prix de ce motif (le prix venu du client, l'adresse de rappel FlexPay
 * ecrite a deux endroits avec deux valeurs). Une seule definition, ici.
 */
class PaiementPrecommande
{
    /**
     * Les deux seuls litteraux acceptes cote FlexPay. « cart » s'ecrit bien
     * ainsi : c'est le contrat de la passerelle, pas une faute a corriger.
     */
    public const METHODES = ['mobile', 'cart'];

    /**
     * Message repris mot pour mot de l'application (CommandeController) : le
     * client doit lire la meme phrase, quel que soit le chemin emprunte.
     */
    public const MESSAGE_MINIMUM_CARTE = "Pour le paiement par cart le montant minimum c'est 2USD";

    public function trouver(string $uid): ?Precommande
    {
        $id = Cipher::Decrypt($uid);

        if ($id === false || ! ctype_digit((string) $id)) {
            return null;
        }

        return Precommande::query()->with(['user', 'town'])->find((int) $id);
    }

    public function methodeConnue(string $method): bool
    {
        return in_array($method, self::METHODES, true);
    }

    /**
     * Reproduction a l'identique du controle de l'application
     * (CommandeController : `$total_price <= 2 && $method == "cart"`).
     *
     * Il ne regarde pas la devise : un montant en francs congolais y echappe
     * donc, alors qu'il est tres en dessous de 2 USD. On ne le corrige pas
     * ici — une divergence entre le chemin application et le chemin lien de
     * paiement serait pire que ce defaut partage.
     */
    public function minimumCarteAtteint(Precommande $precommande, string $method): bool
    {
        return $method !== 'cart' || (float) $precommande->total > 2;
    }

    /**
     * La liste blanche des coordonnees de livraison.
     *
     * Model::unguard() est global dans ce projet : ces regles sont la SEULE
     * chose qui empeche un formulaire d'ecrire n'importe quelle colonne. La
     * commune n'y figure pas, deliberement — voir figerCoordonnees().
     *
     * @return array<string, array<int, string>>
     */
    public function reglesCoordonnees(): array
    {
        return [
            'adresse' => ['required', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'number_street' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:255'],
            'recipient_name' => ['required', 'string', 'max:120'],
            'recipient_phone' => ['required', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messagesCoordonnees(): array
    {
        return [
            'adresse.required' => 'L\'adresse de livraison est obligatoire.',
            'recipient_name.required' => 'Le nom de la personne à livrer est obligatoire.',
            'recipient_phone.required' => 'Le numéro que le livreur appellera est obligatoire.',
        ];
    }

    /**
     * Ecrit les coordonnees champ par champ depuis des donnees DEJA validees,
     * jamais depuis le tableau de requete.
     *
     * La commune n'est jamais reprise du formulaire : elle a servi a choisir
     * la tranche de livraison, donc le total fige. L'accepter laisserait payer
     * un tarif du centre pour une livraison en peripherie.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function figerCoordonnees(Precommande $precommande, array $donnees): void
    {
        $precommande->adresse_delivery = $donnees['adresse'];
        $precommande->street = $donnees['street'] ?? null;
        $precommande->number_street = $donnees['number_street'] ?? null;
        $precommande->reference_adresse = $donnees['reference'] ?? null;
        $precommande->recipient_name = $donnees['recipient_name'];
        $precommande->recipient_phone = $donnees['recipient_phone'];
        $precommande->save();
    }

    /**
     * Le garde contre la TypeError de LibPhoneNumber vit dans le wrapper
     * lui-meme, seule definition de « ce numero est valide ». On ne garde ici
     * qu'un nom lisible au point d'appel.
     */
    public function numeroValide(string $phone): bool
    {
        return (new LibPhoneNumber($phone))->checkValidationNumber();
    }

    /**
     * Le numero debite en mobile money.
     *
     * On peut se faire livrer chez sa mere et payer soi-meme : la case « meme
     * numero » reprend celui du destinataire, sinon c'est celui qui est saisi.
     */
    public function numeroDuPayeur(Precommande $precommande, bool $memeNumero, string $saisi): string
    {
        return $memeNumero ? (string) $precommande->recipient_phone : $saisi;
    }

    /**
     * Une initiation est-elle encore en vol ?
     *
     * Une pre-commande ne se ferme qu'a la reception du webhook. Entre l'appel
     * a la passerelle et cette confirmation, rien n'empechait un second POST de
     * rappeler FlexPay : le telephone du client sonnait deux fois pour la meme
     * commande, et s'il confirmait la premiere sollicitation, la reference
     * enregistree n'etait plus celle qui avait ete payee.
     *
     * `updated_at` fait office d'horodatage : c'est la sauvegarde qui a pose
     * `reference_paiement` qui l'a mis a jour. Au-dela du delai, on suppose la
     * premiere sollicitation perdue et on laisse reessayer.
     */
    public function initiationEnVol(Precommande $precommande): bool
    {
        if (blank($precommande->reference_paiement)) {
            return false;
        }

        $delai = (int) config('precommande.delai_relance_paiement_minutes');

        return $precommande->updated_at !== null
            && $precommande->updated_at->greaterThan(now()->subMinutes($delai));
    }

    /**
     * Appelle FlexPay avec le total fige et enregistre la reference de
     * paiement.
     *
     * @return array<string, mixed>
     */
    public function initierFlexPay(Precommande $precommande, string $phone, string $method): array
    {
        $result = FlexPay::sendData([
            // Le total fige, jamais un montant venu de la requete.
            'amount' => (float) $precommande->total,
            // Chaine vide en carte : c'est ce que fait l'application.
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

        // La reference de la PREMIERE initiation reussie ne se reecrit jamais.
        // Si le client relance et confirme finalement la sollicitation d'avant,
        // ecraser laisserait en base une reference qui ne correspond a aucun
        // paiement. Le webhook, lui, ne s'appuie pas dessus : il retrouve la
        // commande par `refernce` et repose ensuite la reference du
        // prestataire — rien ne depend donc de cette ecriture-ci.
        if ((empty($result['code']) || $result['code'] == 0) && blank($precommande->reference_paiement)) {
            $precommande->reference_paiement = $result['orderNumber'] ?? null;
            $precommande->save();
        }

        return $result;
    }
}
