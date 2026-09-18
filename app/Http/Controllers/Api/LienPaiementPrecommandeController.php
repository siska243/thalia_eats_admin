<?php

namespace App\Http\Controllers\Api;

use App\Helpers\CurrentHelpers;
use App\Http\Controllers\Controller;
use App\Models\Precommande;
use App\Services\PaiementPrecommande;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Le lien de paiement d'une pre-commande, vu depuis le site.
 *
 * La page vit desormais sur thaliaeats.com et parle a ces deux points
 * d'entree. Aucune authentification par jeton : la signature EST
 * l'autorisation, exactement comme sur l'ancienne page Blade. Le client qui
 * commande par la conversation n'a pas de session ouverte sur le site.
 *
 * Pourquoi la signature porte sur l'URL d'API et non sur celle de la page :
 * URL::temporarySignedRoute() signe l'URL COMPLETE, hote compris. Un lien
 * signe pour thaliaeats.com ne peut donc pas etre verifie sur
 * app.thaliaeats.com. On signe l'adresse d'API, et le lien remis au client
 * recopie simplement sa chaine de requete (`expires` et `signature`) ; la page
 * la repasse a chaque appel, et le middleware `signed` valide contre l'URL qui
 * a reellement ete signee. Les deux routes partagent la meme URI : une
 * signature couvre donc la lecture et le paiement, la methode HTTP n'entrant
 * pas dans le calcul.
 */
class LienPaiementPrecommandeController extends Controller
{
    public function __construct(private readonly PaiementPrecommande $paiements) {}

    /**
     * Le recapitulatif : ce que le client paie, et s'il reste des coordonnees
     * a saisir.
     */
    public function show(string $uid): JsonResponse
    {
        $precommande = $this->paiements->trouver($uid);

        if (! $precommande) {
            return ApiResponse::NOT_FOUND('Oups', 'Ce lien de paiement est introuvable.');
        }

        // La signature autorise l'acces ; elle ne dit rien de l'etat. Une
        // pre-commande deja payee ou expiree ne doit rien initier.
        if (! $precommande->estValide()) {
            return $this->indisponible($precommande);
        }

        $precommande->load(['products.product', 'restaurant', 'currency', 'town']);

        return ApiResponse::GET_DATA(['data' => $this->recapitulatif($precommande)]);
    }

    /**
     * Fige les coordonnees si elles ne le sont pas encore, puis initie le
     * paiement. L'ordre des controles reproduit celui de la page Blade : les
     * deux chemins doivent refuser les memes requetes.
     */
    public function payer(Request $request, string $uid): JsonResponse
    {
        if ($refus = $this->refuserJetonDAssistant($request)) {
            return $refus;
        }

        $precommande = $this->paiements->trouver($uid);

        if (! $precommande) {
            return ApiResponse::NOT_FOUND('Oups', 'Ce lien de paiement est introuvable.');
        }

        if (! $precommande->estValide()) {
            return $this->indisponible($precommande);
        }

        // Des coordonnees deja figees ne se reecrivent pas : un lien peut avoir
        // ete transfere, et celui qui l'a ne doit pas pouvoir detourner une
        // livraison deja renseignee. Ce que le formulaire envoie est alors
        // purement ignore — pas meme validee.
        if (! $precommande->coordonneesCompletes()) {
            $validation = Validator::make(
                $request->all(),
                $this->paiements->reglesCoordonnees(),
                $this->paiements->messagesCoordonnees(),
            );

            if ($validation->fails()) {
                return response()->json([
                    'title' => 'Oups',
                    'message' => 'Veuillez compléter les informations de livraison.',
                    'error' => 'coordonnees_invalides',
                    'errors' => $validation->errors()->toArray(),
                ], 422);
            }

            $this->paiements->figerCoordonnees($precommande, $validation->validated());
        }

        $method = (string) $request->input('method', 'mobile');

        if (! $this->paiements->methodeConnue($method)) {
            return ApiResponse::BAD_REQUEST('methode_invalide', 'Oups', 'Choisissez un moyen de paiement.');
        }

        if (! $this->paiements->minimumCarteAtteint($precommande, $method)) {
            return ApiResponse::BAD_REQUEST(
                'minimum_carte',
                'Oups',
                PaiementPrecommande::MESSAGE_MINIMUM_CARTE
            );
        }

        // Le telephone du payeur n'existe qu'en mobile money : la carte est
        // debitee sur la page de la passerelle. Le numero du destinataire, lui,
        // reste obligatoire dans les deux cas — c'est celui du livreur.
        $phone = '';

        if ($method === 'mobile') {
            $phone = $this->paiements->numeroDuPayeur(
                $precommande,
                $request->boolean('meme_numero'),
                (string) $request->input('phone'),
            );

            if (! $this->paiements->numeroValide($phone)) {
                return ApiResponse::BAD_REQUEST(
                    'telephone_invalide',
                    'Oups',
                    'Ce numéro de téléphone n\'est pas valide, vérifiez-le et réessayez.'
                );
            }
        }

        // Une sollicitation deja partie interdit la suivante : sans ce garde, le
        // telephone du client sonne deux fois pour la meme commande. Le garde
        // ne s'applique qu'au mobile money — voir sollicitationEnVol().
        if ($this->paiements->sollicitationEnVol($precommande, $method)) {
            return ApiResponse::BAD_REQUEST(
                'paiement_deja_initie',
                'Paiement déjà lancé',
                'Un paiement vient d\'être lancé pour cette commande. Regardez votre téléphone et validez la demande reçue, ou patientez quelques minutes avant de réessayer.'
            );
        }

        $result = $this->paiements->initierFlexPay($precommande, $phone, $method);

        if (! empty($result['code']) && $result['code'] != 0) {
            return ApiResponse::BAD_REQUEST(
                'paiement_refuse',
                'Oups',
                $result['message'] ?? 'Le paiement n\'a pas pu être initié, réessayez.'
            );
        }

        if ($method === 'cart') {
            // La passerelle carte renvoie l'URL de sa page de saisie dans
            // « url ». Sans elle, mieux vaut le dire au client que le
            // rediriger vers le vide.
            if (empty($result['url'])) {
                return ApiResponse::BAD_REQUEST(
                    'carte_indisponible',
                    'Oups',
                    'Le paiement par carte est momentanément indisponible, réessayez ou choisissez le mobile money.'
                );
            }

            return ApiResponse::GET_DATA([
                'data' => [
                    'method' => 'cart',
                    'url' => $result['url'],
                    'reference_paiement' => $precommande->reference_paiement,
                ],
                'title' => 'Paiement par carte',
                'message' => 'Vous allez être redirigé vers la page sécurisée de notre prestataire.',
            ]);
        }

        // Mobile money : le debit se confirme sur le combine. Le dire est la
        // seule facon d'eviter que le client croie le paiement fait.
        return ApiResponse::GET_DATA([
            'data' => [
                'method' => 'mobile',
                'url' => null,
                'reference_paiement' => $precommande->reference_paiement,
                'phone' => CurrentHelpers::masquer($phone, 6),
            ],
            'title' => 'Paiement en attente',
            'message' => 'Validez le paiement depuis le message reçu sur votre téléphone.',
        ]);
    }

    /**
     * Refuse une ecriture portee par un jeton d'assistant.
     *
     * POURQUOI CE CONTROLE EST ECRIT ICI, ET NON DELEGUE A UN MIDDLEWARE :
     * RefuserAgentSansAbility et EnsureNotAgentToken lisent tous deux
     * `$request->user()`, qui resout le garde PAR DEFAUT — « web », pilote
     * « session » (config/auth.php). Le groupe api n'a deliberement pas de
     * StartSession (HypothesesDeSecuriteTest l'epingle), et cette route ne
     * porte ni `auth:sanctum` ni `ability:` pour faire resoudre le garde
     * sanctum. En production, aucun utilisateur n'est donc jamais resolu ici :
     * ces middlewares laisseraient passer n'importe quel en-tete Authorization.
     * Ne remplacez pas ces trois lignes par un middleware « qui fait deja ca ».
     *
     * Ce que ce controle ferme : lienDePaiement() rend le lien signe au
     * porteur du jeton, donc a l'assistant qui a cree la pre-commande. Sans
     * lui, un agent pourrait faire sonner le telephone d'un client pour un
     * paiement qu'aucun humain n'a confirme — ce que
     * POST /api/precommandes/{uid}/paiement interdit deja par
     * `assistant.emetteur`, et ce que CLAUDE.md appelle une ecriture
     * silencieuse.
     *
     * Le client sur le site n'envoie aucun en-tete Authorization : il n'est
     * pas gene. La lecture du recapitulatif reste ouverte — lire ce qu'on paie
     * ne deplace pas d'argent.
     */
    private function refuserJetonDAssistant(Request $request): ?JsonResponse
    {
        $porteur = auth('sanctum')->user();

        if ($porteur && ! $porteur->tokenCan('*')) {
            return ApiResponse::BAD_REQUEST(
                'jeton_assistant',
                'Oups',
                'Cette action ne peut pas être effectuée depuis un assistant.'
            )->setStatusCode(403);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function recapitulatif(Precommande $precommande): array
    {
        $figees = $precommande->coordonneesCompletes();

        return [
            'uid' => Cipher::Encrypt($precommande->id),
            'reference' => $precommande->refernce,
            'statut' => $precommande->status,
            'expires_at' => $precommande->expires_at,
            'restaurant' => $precommande->restaurant ? [
                'name' => $precommande->restaurant->name,
                'slug' => $precommande->restaurant->slug,
            ] : null,
            // La commune s'affiche, ne se saisit pas : elle a choisi la tranche
            // de livraison, donc le total fige.
            'commune' => $precommande->town?->title,
            'currency' => $precommande->currency ? [
                'code' => $precommande->currency->code,
                'slug' => $precommande->currency->slug,
            ] : null,
            'sous_total' => $precommande->sous_total,
            'frais_livraison' => $precommande->frais_livraison,
            'service_price' => $precommande->service_price,
            'total' => $precommande->total,
            'produits' => $precommande->products->map(fn ($ligne) => [
                'title' => $ligne->product?->title,
                'quantity' => $ligne->quantity,
                'price' => $ligne->price,
            ])->values(),
            'coordonnees_figees' => $figees,
            // Masquees, jamais en clair : le porteur du lien n'est pas
            // forcement celui qui a commande — un lien se transfere et se
            // journalise. Il n'a donc pas a apprendre ou et chez qui livrer.
            'coordonnees' => $figees ? [
                'adresse' => CurrentHelpers::masquer($precommande->adresse_delivery),
                'destinataire' => [
                    'name' => CurrentHelpers::masquer($precommande->recipient_name),
                    'phone' => CurrentHelpers::masquer($precommande->recipient_phone, 6),
                ],
            ] : null,
            // Le client doit savoir avant de choisir que la carte lui sera
            // refusee, pas apres avoir rempli le formulaire.
            'carte_disponible' => $this->paiements->minimumCarteAtteint($precommande, 'cart'),
        ];
    }

    /**
     * 410 Gone, comme la page Blade : la ressource a existe et ne reviendra
     * pas. Le code d'erreur permet a la page de dire laquelle des deux raisons
     * s'applique, plutot que « une erreur est survenue ».
     */
    private function indisponible(Precommande $precommande): JsonResponse
    {
        // Tout ce qui n'a pas ete paye a expire : le statut « expiree » existe
        // en base a cote de l'expiration paresseuse derivee de expires_at, et
        // les deux doivent dire la meme chose au client.
        $expiree = $precommande->status !== Precommande::STATUT_PAYEE;

        return response()->json([
            'title' => 'Oups',
            'message' => $expiree
                ? 'Cette pré-commande a expiré. Demandez-en une nouvelle à votre assistant.'
                : 'Cette pré-commande a déjà été réglée, il n\'y a rien de plus à payer.',
            'error' => $expiree ? 'precommande_expiree' : 'precommande_deja_payee',
        ], 410);
    }
}
