<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PrecommandeRefusee;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PaiementPrecommandeController;
use App\Http\Requests\PrecommandeRequest;
use App\Http\Resources\PrecommandeResource;
use App\Models\Precommande;
use App\Models\Product;
use App\Models\Town;
use App\Services\PrecommandeService;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use App\Wrappers\LibPhoneNumber;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class PrecommandeController extends Controller
{
    public function __construct(private readonly PrecommandeService $precommandes) {}

    public function store(PrecommandeRequest $request): JsonResponse
    {
        $town = Town::query()->where('slug', $request->input('town'))->first();

        if (! $town) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette ville de livraison est introuvable');
        }

        try {
            $lines = $this->resoudreLignes($request->input('products'));
        } catch (ModelNotFoundException) {
            return ApiResponse::BAD_REQUEST(
                'produit_introuvable',
                'Oups',
                'Un des produits demandés est introuvable ou n\'est plus disponible'
            );
        }

        try {
            $precommande = $this->precommandes->creer(
                $request->user(),
                $lines,
                $town,
            );
        } catch (PrecommandeRefusee $e) {
            return ApiResponse::BAD_REQUEST(
                $e->raison,
                'Oups',
                $this->messageDeRefus($e->raison)
            );
        }

        return ApiResponse::SUCCESS_DATA(
            array_merge(
                (new PrecommandeResource($precommande))->toArray($request),
                ['lien_paiement' => $this->lienDePaiement($precommande)],
            ),
            'Pré-commande créée',
            'Votre pré-commande est valable '.config('precommande.validite_heures').' heures.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $precommandes = Precommande::query()
            ->with(['products.product', 'restaurant', 'currency', 'commande'])
            ->where('user_id', $request->user()->id)
            ->where('created_at', '>=', now()->subDays((int) config('precommande.visibilite_jours')))
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::GET_DATA([
            'data' => PrecommandeResource::collection($precommandes),
        ]);
    }

    public function show(Request $request, string $uid): JsonResponse
    {
        $precommande = $this->sienne($request, $uid);

        if (! $precommande) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette pré-commande est introuvable');
        }

        return ApiResponse::GET_DATA([
            'data' => array_merge(
                (new PrecommandeResource($precommande))->toArray($request),
                // Un lien n'a de sens que sur une pré-commande encore payable.
                ['lien_paiement' => $precommande->estValide() ? $this->lienDePaiement($precommande) : null],
            ),
        ]);
    }

    public function payer(Request $request, string $uid): JsonResponse
    {
        $precommande = $this->sienne($request, $uid);

        if (! $precommande) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette pré-commande est introuvable');
        }

        if (! $precommande->estValide()) {
            return ApiResponse::BAD_REQUEST(
                'precommande_indisponible',
                'Oups',
                'Cette pré-commande a expiré ou a déjà été payée.'
            );
        }

        // L'assistant ne collecte que la commune : une pré-commande naît donc
        // sans adresse, et les colonnes sont nullables pour cette raison. Ce
        // second point d'entrée — le paiement depuis l'application, sans passer
        // par le lien signé — n'a pas le formulaire qui les remplit. Sans ce
        // garde, il produirait une commande payée que personne ne peut livrer.
        if (! $precommande->coordonneesCompletes()) {
            return ApiResponse::BAD_REQUEST(
                'coordonnees_manquantes',
                'Oups',
                'Veuillez d\'abord indiquer où livrer cette commande.'
            );
        }

        $phone = (string) $request->input('phone');

        if (! (new LibPhoneNumber($phone))->checkValidationNumber()) {
            return ApiResponse::BAD_REQUEST('telephone_invalide', 'Oups', 'Numéro de téléphone invalide.');
        }

        $result = app(PaiementPrecommandeController::class)->initierFlexPay($precommande, $phone, 'mobile');

        if (! empty($result['code']) && $result['code'] != 0) {
            return ApiResponse::BAD_REQUEST('paiement_refuse', 'Oups', $result['message'] ?? 'Paiement impossible.');
        }

        return ApiResponse::GET_DATA($result);
    }

    /**
     * Restreint aux pré-commandes de l'utilisateur courant : celle d'un autre
     * doit être indiscernable d'un identifiant inexistant.
     */
    protected function sienne(Request $request, string $uid): ?Precommande
    {
        $id = Cipher::Decrypt($uid);

        if ($id === false || ! ctype_digit((string) $id)) {
            return null;
        }

        return Precommande::query()
            ->with(['products.product', 'restaurant', 'currency', 'commande'])
            ->where('user_id', $request->user()->id)
            ->find((int) $id);
    }

    /**
     * Le lien que le client reçoit : une page du SITE, plus une page Blade
     * servie par le backend.
     *
     * URL::temporarySignedRoute() signe l'URL complète, hôte compris : un lien
     * signé pour thaliaeats.com ne peut donc pas être vérifié sur
     * app.thaliaeats.com. On signe l'adresse d'API — celle qui sera réellement
     * appelée et validée — et on ne recopie dans le lien du client que sa
     * chaîne de requête (`expires` et `signature`). La page la repasse à chaque
     * appel, et le middleware `signed` valide contre l'URL signée.
     *
     * L'origine du site vient de config('site.url'), pas d'APP_URL : cette
     * dernière n'est pas maintenue comme l'origine publique du projet
     * (.env.example la livre sur 127.0.0.1:8000).
     */
    protected function lienDePaiement(\App\Models\Precommande $precommande): string
    {
        $uid = Cipher::Encrypt($precommande->id);

        $signee = URL::temporarySignedRoute(
            'api.precommande.lien-paiement',
            $precommande->expires_at,
            ['uid' => $uid],
        );

        $query = parse_url($signee, PHP_URL_QUERY);

        // ATTENTION — cet encodage n'est PAS celui de Laravel, et il ne faut
        // pas s'appuyer sur une symétrie qui n'existe pas.
        //
        // `RouteUrlGenerator::to()` fait `strtr(rawurlencode($uri),
        // $dontEncode)`, et `$dontEncode` ramène notamment « %2B → + »,
        // « %3D → = » et « %2F → / » : Laravel laisse donc ces caractères BRUTS
        // dans le chemin qu'il signe, là où `rawurlencode` ci-dessous les code,
        // tout comme `encodeURIComponent` côté site. Un uid qui en contiendrait
        // produirait deux chemins différents, et la signature serait rejetée.
        //
        // Sans conséquence en pratique : l'uid est un base64 de base64, et un
        // balayage de 200 000 identifiants n'en a pas trouvé un seul portant
        // « + », « / » ou « = ». Un « / » casserait de toute façon aussi la
        // route Blade historique, bien avant ce lot. Mais si les clés de
        // `Cipher` changent un jour, c'est ici qu'il faudra revenir.
        return config('site.url').'/paiement/precommande/'.rawurlencode($uid).($query ? '?'.$query : '');
    }

    private function messageDeRefus(string $raison): string
    {
        return match ($raison) {
            PrecommandeRefusee::AUCUN_TARIF_LIVRAISON => 'Nous ne livrons pas encore dans cette zone.',
            PrecommandeRefusee::PANIER_HORS_TRANCHE => 'Ce panier dépasse nos tranches de livraison. Réduisez la commande ou passez par l\'application.',
            PrecommandeRefusee::REFERENCE_INDISPONIBLE => 'Une erreur technique empêche la création de la pré-commande, réessayez.',
            'multi_restaurant' => 'Une commande ne peut contenir que des plats d\'un seul restaurant.',
            'devises_melangees' => 'Tous les plats doivent être dans la même devise.',
            'panier_vide' => 'Veuillez indiquer au moins un produit.',
            'quantite_invalide' => 'La quantité doit être au moins égale à 1.',
            default => 'Cette commande ne peut pas être créée.',
        };
    }

    /**
     * @param  array<int, array{uid: string, quantity: int|float}>  $products
     * @return array<int, array{product: Product, quantity: int|float}>
     *
     * @throws ModelNotFoundException
     */
    protected function resoudreLignes(array $products): array
    {
        $ids = [];

        foreach ($products as $entry) {
            $id = Cipher::Decrypt($entry['uid']);

            if ($id === false || $id === '' || ! ctype_digit((string) $id)) {
                throw new ModelNotFoundException;
            }

            $ids[$entry['uid']] = (int) $id;
        }

        $trouves = Product::query()
            ->with('currency')
            ->whereIn('id', array_values($ids))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($products as $entry) {
            $product = $trouves->get($ids[$entry['uid']]);

            if (! $product) {
                throw new ModelNotFoundException;
            }

            $lines[] = ['product' => $product, 'quantity' => $entry['quantity']];
        }

        return $lines;
    }
}
