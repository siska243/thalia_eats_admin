<?php

namespace App\Http\Controllers\Api;

use App\Events\PayementEvent;
use App\Helpers\CurrentHelpers;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommandeRequest;
use App\Http\Resources\CommandeResource;
use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Payement;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\StatusPayement;
use App\Models\Town;
use App\Services\Portee;
use App\Services\PorteeLivraison;
use App\Services\Quotation;
use App\Services\QuotationService;
use App\Services\Suivi\SuiviPositions;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use App\Wrappers\FirebasePushNotification;
use App\Wrappers\FlexPay;
use App\Wrappers\LibPhoneNumber;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

class CommandeController extends Controller
{
    /**
     * Display a listing of the resource.
     * commande passed
     */
    public function index()
    {
        //
        try {

            $user = Auth()->user();

            $commande = Commande::with('product')->where('status_id', '!=', 1)->where('status_id', '!=', 2)->where('user_id', $user->id)->get();

            return ApiResponse::GET_DATA(CommandeResource::collection($commande));

        } catch (Exception $e) {

            return ApiResponse::SERVER_ERROR($e);
        }
    }

    /**
     * Refuse toute ecriture sur une commande qui n'est plus un panier.
     *
     * Quatre routes composaient le panier : `store`, `add-product`,
     * `delete-product` et `update-address-delivery`. Seule la premiere avait
     * ete fermee, en comparant status_id a 5 sur place ; les trois autres
     * laissaient un client reecrire sa commande pendant que la passerelle en
     * tenait deja le montant. Il payait un panier et en recevait un autre.
     *
     * La question est posee une seule fois ici, au statut effectif, parce
     * qu'un garde par chemin est precisement le motif qui a deja produit deux
     * bugs de production sur ce depot.
     *
     * La forme de la reponse est celle que `store` renvoyait deja : les deux
     * clients savent la lire et proposent de payer ou d'annuler.
     */
    private function refuserSiVerrouillee(?Commande $commande): ?JsonResponse
    {
        if (! $commande || $commande->estModifiable()) {
            return null;
        }

        return ApiResponse::BAD_REQUEST(
            ['uid' => Cipher::Encrypt($commande->id), 'reference' => $commande->refernce],
            __('Commande en attente'),
            __("Votre commande #:reference n'est pas encore réglée. Payez-la ou annulez-la depuis « Mes commandes » avant d'en passer une nouvelle.", ['reference' => $commande->refernce])
        );
    }

    /**
     * Refuse une commande dont un produit vient d'un restaurant ferme.
     *
     * Le proprietaire, le 10 octobre : « on refuse car c'est fermer et
     * personne peut preparer la commande ». Donc aucune marge, et c'est
     * l'heure du REGLEMENT qui compte, pas celle ou le panier a ete rempli :
     * un panier constitue avant la fermeture et paye apres est refuse.
     *
     * La regle d'ouverture elle-meme vit sur `Restaurant::estOuvert()`, une
     * seule fois. Elle etait ecrite dans les deux applications et nulle part
     * sur le serveur : le site affichait « Ferme » et laissait payer, le
     * mobile grisait son bouton, et un appel direct a l'API passait dans les
     * deux cas.
     *
     * Le message nomme l'heure de reouverture quand il y en a une : « ouvre a
     * 9h » dit au client quoi faire, « ferme » le laisse devant une impasse.
     *
     * @param  array<int, array<string, mixed>>|null  $products  Les lignes recues, portant un uid chiffre.
     */
    private function refuserSiRestaurantFerme(?array $products, string $cle = 'product_id'): ?JsonResponse
    {
        $ids = collect($products ?? [])
            ->map(fn ($ligne) => Cipher::Decrypt($ligne[$cle] ?? $ligne['uid'] ?? null))
            ->filter(fn ($id) => $id !== false && $id !== '' && ctype_digit((string) $id))
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! $ids) {
            return null;
        }

        $ferme = Product::query()
            ->with('restaurant')
            ->whereIn('id', $ids)
            ->get()
            ->pluck('restaurant')
            ->filter()
            ->unique('id')
            ->first(fn (Restaurant $restaurant) => ! $restaurant->estOuvert());

        if (! $ferme) {
            return null;
        }

        $ouvreA = $ferme->ouvreA();

        return ApiResponse::BAD_REQUEST(
            ['restaurant' => $ferme->name],
            __('Restaurant fermé'),
            $ouvreA
                ? __(':restaurant est fermé. Il ouvre à :heure.', ['restaurant' => $ferme->name, 'heure' => $ouvreA])
                : __(":restaurant est fermé et ne prend pas de commande pour l'instant.", ['restaurant' => $ferme->name]),
        );
    }

    /**
     * Refuse une commande dont le restaurant est trop loin de la livraison.
     *
     * Jumelle de `refuserSiRestaurantFerme`, et pour la meme raison : une
     * regle qui ne vit que dans les ecrans ne protege rien. Le proprietaire,
     * le 15 octobre : « une personne a Kinshasa ne peut pas commander dans un
     * restaurant au bas-Congo ».
     *
     * La regle elle-meme vit sur `PorteeLivraison`, une seule fois, et c'est
     * elle qui decide quoi faire d'une position manquante — jamais ce
     * controleur.
     *
     * Elle n'est appelee que la ou une adresse de livraison existe :
     * `store()` et `valide()`. Le panier (`addProduct`) n'en a pas, et une
     * distance ne se calcule pas sans destination.
     *
     * @param  array<int, array<string, mixed>>|null  $products
     */
    private function refuserSiRestaurantTropLoin(
        ?array $products,
        ?Town $town,
        ?float $lat = null,
        ?float $lng = null,
        string $cle = 'uid',
    ): ?JsonResponse {
        $ids = collect($products ?? [])
            ->map(fn ($ligne) => Cipher::Decrypt($ligne[$cle] ?? $ligne['uid'] ?? null))
            ->filter(fn ($id) => $id !== false && $id !== '' && ctype_digit((string) $id))
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! $ids) {
            return null;
        }

        $portees = app(PorteeLivraison::class);

        $refus = Product::query()
            ->with('restaurant.town.city')
            ->whereIn('id', $ids)
            ->get()
            ->pluck('restaurant')
            ->filter()
            ->unique('id')
            ->map(fn (Restaurant $restaurant) => $portees->pour($restaurant, $town, $lat, $lng))
            ->first(fn (Portee $portee) => ! $portee->autorise);

        if (! $refus) {
            return null;
        }

        return ApiResponse::BAD_REQUEST(
            ['distance_km' => $refus->distance_km, 'rayon_km' => $refus->rayon_km],
            __('Hors zone de livraison'),
            $refus->message,
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CommandeRequest $request)
    {
        //
        try {

            $products = $request->products;
            $pricing = $request->pricing;
            $adresse = $request->adresse;

            $url = $request->url;

            $user = auth()->user();

            /*
             * Les commandes perimees sont annulees avant toute chose.
             *
             * La lecture les ignore deja — `nonReglee()` les exclut — mais
             * sans cette ecriture elles resteraient affichees « en attente »
             * au client et dans l'administration jusqu'au prochain passage de
             * la tache planifiee. Le client ne doit pas voir deux commandes
             * dont l'une est un fantome.
             */
            Commande::expirerPour($user->id);

            $last_commande = Commande::query()->orderBy('created_at', 'desc')->first();
            $commande = Commande::query()->nonReglee()->where('user_id', $user->id)->first();

            if ($refus = $this->refuserSiVerrouillee($commande)) {
                return $refus;
            }

            $town = Town::query()->where('id', Cipher::Decrypt($adresse['town']['uid']))->first();

            // Avant toute ecriture : une commande posee puis refusee laisserait
            // une ligne orpheline, et le client la verrait dans sa liste.
            if ($refus = $this->refuserSiRestaurantTropLoin(
                $products,
                $town,
                isset($adresse['lat']) ? (float) $adresse['lat'] : null,
                isset($adresse['long']) ? (float) $adresse['long'] : null,
            )) {
                return $refus;
            }

            if (! $commande) {

                $commande = new Commande;
                $refernce = $last_commande ? 1000 + $last_commande->id : 1000;
                $commande->user_id = $user->id;
                $commande->refernce = $refernce;
                $commande->status_id = 1;

            }

            $commande->price_delivery = $pricing['frais_livraison'];
            $commande->price_service = $pricing['service_price'];

            $commande->town_id = $town->id;
            $commande->reference_adresse = ! empty($adresse['reference']) ? $adresse['reference'] : null;
            $commande->adresse_delivery = $adresse['adresse'];
            $commande->street = $adresse['street'];
            $commande->number_street = $adresse['number_street'];

            // Coordonnees choisies sur la carte : sans elles, le livreur ne
            // recoit qu'un texte libre.
            $commande->lat = $adresse['lat'] ?? null;
            $commande->long = $adresse['long'] ?? null;

            // Commande pour un tiers : le livreur doit joindre la personne a
            // livrer, pas le titulaire du compte.
            $commande->recipient_name = $request->input('recipient_name');
            $commande->recipient_phone = $request->input('recipient_phone');
            $commande->save();

            if (! empty($products)) {
                return ApiResponse::BAD_REQUEST(__('Oups'), __('Error'), __('Veuillez ajouter au moins un produits'));
            }

            foreach ($products as $product) {
                // code...
                $product_id = Product::query()->find(Cipher::Decrypt($product['uid']));
                $commande_product = CommandeProduct::query()
                    ->where('user_id', $user->id)
                    ->where('product_id', $product_id->id)->where('commande_id', $commande->id)->first();
                if (! $commande_product) {
                    $commande_product = new CommandeProduct;
                }
                $commande_product->product_id = $product_id->id;
                $commande_product->price = $product_id->price;
                $commande_product->quantity = intval($product['quantity']);
                $commande_product->commande_id = $commande->id;
                $commande_product->user_id = $user->id;
                $commande_product->currency_id
                    = $pricing['currency']['id'];
                $commande_product->save();
            }

            // Livraison et service compris : l'ecran presente ce champ comme
            // « Total », il doit etre le montant qu'on paiera.
            $commande->load('product');
            $commande->recalculerTotal();

            $commande->save();

            return ApiResponse::SUCCESS_DATA(new CommandeResource($commande), 'Commande ajouter', 'La commande a été ajouter avec succès');

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function addProduct(Request $request)
    {

        try {
            $products = $request->input('products');

            $user = auth()->user();
            $last_commande = Commande::query()->orderBy('created_at', 'desc')->first();
            $commande = Commande::query()->nonReglee()->where('user_id', $user->id)->first();

            // Avant la creation du panier de repli : sans ce refus, un client
            // ayant une commande au paiement en aurait ouvert une seconde par
            // cet appel, ce qui contourne aussi le « une commande a la fois ».
            if ($refus = $this->refuserSiVerrouillee($commande)) {
                return $refus;
            }

            if ($refus = $this->refuserSiRestaurantFerme($products)) {
                return $refus;
            }

            if (! $commande) {

                $commande = new Commande;
                $refernce = $last_commande ? 1000 + $last_commande->id : 1000;
                $commande->user_id = $user->id;
                $commande->refernce = $refernce;
                $commande->status_id = 1;

            }

            if (count($products) > 0) {
                foreach ($products as $product) {

                    $product_id = Product::query()->find(Cipher::Decrypt($product['product_id']));
                    $commande_product = CommandeProduct::query()->where('product_id', $product_id->id)
                        ->where('user_id', $user->id)
                        ->where('commande_id', $commande->id)->first();
                    if (! $commande_product) {
                        $commande_product = new CommandeProduct;
                    }

                    $commande_product->product_id = $product_id->id;
                    $commande_product->price = $product_id->price;
                    $commande_product->quantity = intval($product['quantity']);
                    $commande_product->currency_id = $product['pricing'];
                    $commande_product->commande_id = $commande->id;
                    $commande_product->user_id = $user->id;

                    $commande_product->save();
                    $commande_product->refresh();
                }
            }

            /*
             * Le total ne comptait que les lignes du dernier produit ajoute,
             * et l'affectation `=` au lieu de `+=` n'en gardait qu'une seule :
             * un panier de trois plats s'affichait au prix du dernier. Il
             * dependait de surcroit d'une variable de boucle, donc d'un appel
             * sans produit pour planter.
             *
             * Livraison et service y sont compris, parce que les deux clients
             * affichent ce champ sous le libelle « Total ».
             */
            $commande->load('product');
            $commande->recalculerTotal();
            $commande->save();

            return $this->current();

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    /**
     * Change l'adresse de livraison d'une commande non encore livree.
     *
     * Trois defauts corriges ici :
     *
     * - la commande etait retrouvee par « la premiere en attente de cet
     *   utilisateur », sans identifiant : un client ayant deux commandes en
     *   attente voyait la mauvaise etre modifiee. Le uid est desormais
     *   accepte, l'ancien comportement restant le repli pour les versions
     *   deja installees ;
     * - aucune verification d'existence : sans commande en attente, l'appel
     *   ecrivait sur null et repondait 500 ;
     * - les coordonnees n'etaient pas prises en compte, alors que le livreur
     *   s'en sert desormais.
     */
    public function updateDeliveryAddress(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'town' => ['required', 'string', 'exists:towns,slug'],
                'street' => ['nullable', 'string', 'max:255'],
                'number_street' => ['nullable', 'string', 'max:50'],
                'reference' => ['nullable', 'string', 'max:255'],
                'adresse' => ['nullable', 'string', 'max:255'],
                'lat' => ['nullable', 'numeric', 'between:-90,90'],
                'long' => ['nullable', 'numeric', 'between:-180,180'],
            ]);

            if ($validator->fails()) {
                return ApiResponse::BAD_REQUEST(
                    $validator->errors(),
                    'Oups',
                    'Indiquez une adresse et une commune desservie'
                );
            }

            $user = Auth()->user();
            $uid = $request->input('uid');

            $commande = Commande::query()
                ->with('product')
                ->where('user_id', $user?->id)
                ->nonReglee()
                ->when($uid, fn ($query) => $query->where('id', Cipher::Decrypt($uid)))
                ->first();

            if ($refus = $this->refuserSiVerrouillee($commande)) {
                return $refus;
            }

            if (! $commande) {
                return ApiResponse::NOT_FOUND(
                    'Oups',
                    'Aucune commande en attente ne correspond'
                );
            }

            $street = $request->input('street');
            $number_street = $request->input('number_street');
            $reference = $request->input('reference');

            $commande->town_id = Town::query()->where('slug', $request->input('town'))->first()?->id;
            $commande->reference_adresse = $reference;
            $commande->adresse_delivery = $request->input('adresse')
                ?: trim("{$street} {$number_street} {$reference}");
            $commande->street = $street;
            $commande->number_street = $number_street;
            $commande->lat = $request->input('lat');
            $commande->long = $request->input('long');

            $commande->save();

            return $this->current();
        } catch (Exception $e) {

            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function cancel(Request $request)
    {
        try {

            $user = Auth()->user();
            $commandId = $request->input('uid');

            if (! $commandId) {
                return ApiResponse::BAD_REQUEST(__('Error'), __('Oups'), __('Commande is required'));
            }
            $commande = Commande::with('product')->nonReglee()
                ->where('id', Cipher::Decrypt($commandId))
                ->where('user_id', $user?->id)
                ->latest()
                ->first();

            if (! $commande) {
                return ApiResponse::NOT_FOUND(__('messages.commandes.not_found'), __('messages.commandes.not_found'));
            }

            $commande->status_id = 4;
            $commande->cancel_at = now()->format('Y-m-d H:i:s');
            $commande->save();

            return ApiResponse::SUCCESS_DATA([]);
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    /**
     * Display the specified resource.
     * current commande
     */
    public function current()
    {
        try {

            $user = Auth()->user();

            $commande = Commande::with('product')->nonReglee()->where('user_id', $user?->id)->first();

            if (! $commande) {
                return ApiResponse::NOT_FOUND(__('Not found'), __('messages.commandes.not_found'));
            }

            return ApiResponse::GET_DATA(new CommandeResource($commande));

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function traitement()
    {
        try {

            $user = Auth()->user();

            // Sans ce filtre, une commande annulee depuis l'administration
            // s'affichait au client comme etant en route.
            $commande = Commande::with(['product', 'delivrery_driver', 'status'])
                ->whereIn('status_id', [2])
                ->nonAnnulee()
                ->where('user_id', $user->id)
                ->get();

            return ApiResponse::GET_DATA(CommandeResource::collection($commande));

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function deleteProduct(Request $request)
    {
        try {

            $user = Auth()->user();

            $product_id = $request->input('product_id');
            $commande = Commande::with('product')->nonReglee()->where('user_id', $user?->id)->first();

            if ($refus = $this->refuserSiVerrouillee($commande)) {
                return $refus;
            }

            // Sans commande en cours il n'y a rien a retirer : l'appel ecrivait
            // sur null et repondait 500.
            if (! $commande) {
                return ApiResponse::NOT_FOUND('Oups', 'Aucune commande en attente ne correspond');
            }

            CommandeProduct::query()->where('commande_id', $commande->id)->where('product_id', Cipher::Decrypt($product_id))->delete();

            return $this->current();

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function track()
    {
        try {

            $user = Auth()->user();

            // `nonExpiree()` autant que `nonAnnulee()` : une commande en
            // attente de paiement dont le delai est passe est annulee pour le
            // serveur. Sans ce filtre, elle restait listee « en cours » alors
            // que plus rien ne pouvait lui arriver, ni paiement ni annulation.
            $commande = Commande::with(['product', 'delivrery_driver', 'status'])
                ->whereIn('status_id', [Commande::STATUT_EN_COURS, Commande::STATUT_ATTENTE_PAIEMENT])
                ->nonAnnulee()
                ->nonExpiree()
                ->where('user_id', $user->id)
                ->latest()
                ->get();

            return ApiResponse::GET_DATA(CommandeResource::collection($commande));

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function historique()
    {
        try {

            $user = Auth()->user();

            $commande = Commande::with(['product', 'delivrery_driver', 'status'])->whereIn('status_id', [3, 4])
                ->where('user_id', $user->id)
                ->latest()
                ->get();

            return ApiResponse::GET_DATA(CommandeResource::collection($commande));

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    /**
     * La fiche d'une commande, retrouvee par sa reference.
     *
     * Elle n'etait filtree par rien : `where('refernce', ...)` et c'est tout.
     * Et la reference n'est meme pas chiffree, c'est 1000 + l'identifiant de
     * la commande precedente. Un seul compte suffisait donc a parcourir
     * toutes les commandes de la plateforme, avec l'adresse de livraison, le
     * nom et le telephone du destinataire et les coordonnees GPS. Le filtre
     * est le meme que pour showOrder : client, livreur affecte, restaurant
     * concerne.
     */
    public function show(Commande $commande)
    {
        $commande = $this->clauseAcces(
            Commande::query()->with(['product', 'delivrery_driver', 'status'])->whereKey($commande->getKey())
        )->first();

        // Une commande d'autrui et une reference inconnue donnent la meme
        // reponse : sinon l'ecart renseigne sur l'existence des commandes.
        if (! $commande) {
            return ApiResponse::NOT_FOUND('Oups', 'Commande introuvable');
        }

        return ApiResponse::GET_DATA(new CommandeResource($commande));
    }

    /**
     * Retrouve une commande accessible au demandeur.
     *
     * L'uid n'est pas une capacite : Cipher chiffre un entier avec une cle et
     * un IV ecrits dans le depot, sans MAC. N'importe qui peut donc forger
     * l'uid de n'importe quelle commande. L'autorisation doit venir d'une
     * clause SQL, pas du fait de connaitre l'identifiant.
     *
     * Trois profils y ont legitimement acces : le client qui a commande, le
     * livreur qui en a la charge, et le restaurant dont elle contient les
     * plats.
     */
    private function commandeAccessible(?string $uid, array $with = []): ?Commande
    {
        $id = Cipher::Decrypt((string) $uid);

        if ($id === false || ! ctype_digit((string) $id)) {
            return null;
        }

        if (! auth()->check()) {
            return null;
        }

        return $this->clauseAcces(
            Commande::query()->with($with)->where('id', (int) $id)
        )->first();
    }

    /**
     * La clause d'acces, ecrite une seule fois.
     *
     * Trois profils y ont legitimement acces : le client qui a commande, le
     * livreur qui en a la charge, et le restaurant dont elle contient les
     * plats. showOrder, get_track_order et show la partagent ; une seconde
     * copie aurait diverge des la premiere evolution du modele, comme la
     * definition « commande non reglee » l'a deja fait huit fois ici.
     */
    private function clauseAcces(Builder $query): Builder
    {
        $user = auth()->user();

        return $query->where(function (Builder $sous) use ($user) {
            $sous
                ->where('user_id', $user?->id)
                ->orWhereHas('delivrery_driver', fn ($q) => $q->where('user_id', $user?->id))
                ->orWhereHas(
                    'commande_products.product.restaurant',
                    fn ($q) => $q->where('user_id', $user?->id)
                );
        });
    }

    public function showOrder(string $uid)
    {
        $commande = $this->commandeAccessible($uid, ['product', 'delivrery_driver', 'status']);

        // Un uid inconnu et un uid appartenant a autrui donnent la meme
        // reponse : sinon l'ecart renseigne sur l'existence des commandes.
        // L'ancienne version renvoyait en plus l'identifiant entier decode.
        if (! $commande) {
            return ApiResponse::NOT_FOUND('Oups', 'Commande introuvable');
        }

        return ApiResponse::GET_DATA(new CommandeResource($commande));
    }

    /**
     * Update the specified resource in storage.
     */
    public function track_order(Request $request)
    {
        try {
            $order = $request->input('uid');
            $location = $request->input('location');

            if (! $order) {
                return ApiResponse::BAD_REQUEST(__('Oups'), __('error'), __('messages.commandes.not_found'));
            }

            if (! $location) {
                return ApiResponse::BAD_REQUEST(__('Oups'), __('error'), __('messages.commandes.not_found'));
            }

            // Remonter une position n'appartient qu'au livreur affecte : sans
            // ce filtre, tout compte authentifie pouvait faire croire au
            // client que son repas approchait.
            $current_order = Commande::query()
                ->where('id', Cipher::Decrypt($order))
                ->where('status_id', 2)
                ->whereHas('delivrery_driver', fn ($q) => $q->where('user_id', auth()->id()))
                ->first();

            if ($current_order) {
                /*
                 * L'ecriture passe par le service, qui tient la cadence et la
                 * fenetre active. Les livreurs emettaient parfois plusieurs
                 * fois par seconde et la table accumulait tout ; le refus est
                 * silencieux, parce que l'application installee ne sait pas
                 * le lire et le repeterait sans fin.
                 */
                app(SuiviPositions::class)->enregistrer($current_order, $location);
            }

            return ApiResponse::SUCCESS_DATA([]);

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function get_track_order($uid)
    {
        try {

            $commande = $this->commandeAccessible($uid);

            if (! $commande) {
                return ApiResponse::NOT_FOUND('Oups', 'Commande introuvable');
            }

            /*
             * Le DERNIER releve, et non le premier.
             *
             * `first()` sans tri rendait la plus ancienne ligne : le plan du
             * client affichait la position ou le livreur se trouvait au
             * moment du tout premier releve, et ne bougeait plus jusqu'a la
             * livraison. Le point restait fige a l'endroit ou le livreur
             * avait quitte le restaurant.
             *
             * `latest('id')` et non `latest('created_at')` : deux releves
             * peuvent partager la meme seconde, seul l'ordre d'insertion ne
             * ment pas. La lecture passe par le service, qui porte deja cette
             * definition pour la location.
             */
            $data = app(SuiviPositions::class)->dernier($commande);

            return ApiResponse::GET_DATA($data);
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function swr_check_paiement($orderNumber)
    {

        try {
            /*
             * La commande est d'abord reconnue comme celle du demandeur.
             *
             * L'ancienne version partait directement interroger FlexPay avec
             * la reference recue, puis ecrivait le statut et declenchait la
             * notification au restaurateur sur la commande que la passerelle
             * lui nommait. La reference est une suite d'entiers : tout compte
             * authentifie pouvait donc suivre, et faire avancer, le paiement
             * de n'importe qui.
             */
            $commande = Commande::query()
                ->where('refernce', $orderNumber)
                ->where('user_id', auth()->id())
                ->first();

            if (! $commande) {
                return ApiResponse::NOT_FOUND('Oups', 'Commande introuvable');
            }

            $result = FlexPay::checkPaiement($orderNumber);
            if ($result['code'] != 0) {

                return ApiResponse::BAD_REQUEST('Oups!!', $result['title'], $result['message']);
            } else {

                $status = $result['transaction']['status'];

                $status_paiement = StatusPayement::query()->where('code', $status)->first();

                if ($status_paiement?->is_paid) {

                    // La commande deja reconnue, et non celle que la reponse
                    // de la passerelle designe : sinon la verification de
                    // propriete faite plus haut ne servirait a rien.
                    $order = $commande->load('user');

                    $order->status_id = 2;
                    $order->reference_paiement = $result['transaction']['provider_reference'];
                    // envoyer la commande au restaurateur
                    if (! $order->paied_at) {
                        $order->paied_at = now()->format('Y-m-d H:i:s');

                        $user = CurrentHelpers::getUserByOrder($order);

                        $body = [
                            'action' => 'new-order',
                            'status' => $status_paiement,
                        ];

                        if ($user->expo_push_token) {
                            $push = new FirebasePushNotification;
                            $push->sendPushNotification($user->expo_push_token, 'Nouvelle commande', json_encode($body));

                            FirebasePushNotification::sendNotification($user->expo_push_token, 'Thalia eats commande', 'Nouvelle commande');
                        }
                    }

                    $order->save();

                }

                return ApiResponse::GET_DATA($status_paiement);
            }
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    /**
     * Le repli sur le total du client, une fois le serveur declare souverain.
     *
     * Retomber sur le montant annonce par le telephone n'est alors plus un
     * filet, c'est une porte : il suffit de faire echouer la cotation pour
     * reprendre la main sur le prix. Chaque cas est donc tranche ici, et
     * nulle part ailleurs.
     *
     * Refuses — on ne facture pas un panier qu'on ne sait pas lire :
     * - produit_introuvable : un uid qui ne resout a rien. La suite de
     *   valide() plantait de toute facon sur ce panier ;
     * - panier_vide / quantite_invalide : il n'y a rien a facturer ;
     * - commune_inconnue : sans commune, pas de grille, donc pas de prix.
     *
     * Gardent le repli, faute d'arbitrage du proprietaire :
     * - multi_restaurant : trois commandes livrees en production sont dans ce
     *   cas et aucun des deux paniers ne l'interdit — refuser casserait un
     *   parcours reel ;
     * - devises_melangees : meme raison ;
     * - erreur_technique : une panne de la mesure ne peut pas, a elle seule,
     *   decider de perdre une commande.
     * Ces trois-la sont journalises en alerte : ils doivent se voir.
     */
    private function refuserFauteDeCotation(?Quotation $quotation, ?string $indisponibilite, $reference): ?JsonResponse
    {
        $refus_moteur = [
            QuotationService::RAISON_PANIER_VIDE,
            QuotationService::RAISON_QUANTITE_INVALIDE,
        ];

        $motif = null;

        if ($quotation !== null && ! $quotation->disponible && in_array($quotation->raison, $refus_moteur, true)) {
            $motif = $quotation->raison;
        } elseif (in_array($indisponibilite, ['produit_introuvable', 'panier_vide', 'commune_inconnue'], true)) {
            $motif = $indisponibilite;
        }

        if ($motif !== null) {
            $this->journalQuotation('error', 'refus_facturation', [
                'commande' => $reference,
                'raison' => $motif,
            ]);

            return ApiResponse::BAD_REQUEST(
                'panier_impossible_a_chiffrer',
                __('Oups'),
                __("Votre panier n'a pas pu être chiffré. Actualisez-le, puis réessayez.")
            );
        }

        $repli = $quotation !== null && ! $quotation->disponible
            ? $quotation->raison
            : $indisponibilite;

        if ($repli !== null) {
            $this->journalQuotation('alert', 'repli_prix_client', [
                'commande' => $reference,
                'raison' => $repli,
            ]);
        }

        return null;
    }

    /**
     * Journaliser ne doit jamais faire echouer une commande : le canal
     * « quotation » est peut-etre precisement ce qui vient de tomber.
     *
     * @param  array<string, mixed>  $contexte
     */
    private function journalQuotation(string $niveau, string $evenement, array $contexte): void
    {
        try {
            Log::channel('quotation')->{$niveau}($evenement, $contexte);
        } catch (Throwable) {
            // Rien a faire.
        }
    }

    public function valide(Request $request)
    {
        try {

            $success_url = $request->input('success_url');
            $error_url = $request->input('error_url');
            $cancle_url = $request->input('cancel_url');
            // Cette adresse est appelee par le serveur depuis le webhook de
            // paiement, qui est public. La laisser venir de la requete
            // revenait a offrir un POST vers l'hote de son choix — metadonnees
            // cloud, services internes, proxy Ollama en 127.0.0.1:11434.
            $webhook_url = config('sse.webhook_url');
            $pricing = $request->input('pricing');
            $phone = $request->input('phone');
            $method = $request->input('method', 'mobile');
            $total_price = $request->input('total_price');
            $mobile = $request->input('mobile');

            $user = auth()->user();
            $user->phone = $mobile;

            if (! $user) {
                return ApiResponse::NOT_AUTHORIZED();
            }

            // Les commandes perimees sont annulees avant le controle : sinon le
            // client reste bloque un quart d'heure par une commande morte,
            // sans savoir combien de temps ni pourquoi.
            Commande::expirerPour($user->id);

            $last_commande = Commande::query()->orderBy('created_at', 'desc')->first();
            $commande = Commande::query()->nonReglee()->where('user_id', $user?->id)->first();

            if ($commande) {
                // Le message nomme la commande concernee : sans sa reference,
                // le client cherche dans une liste sans savoir laquelle
                // l'empeche de commander.
                return ApiResponse::BAD_REQUEST(
                    ['uid' => Cipher::Encrypt($commande->id), 'reference' => $commande->refernce],
                    __('Commande en attente'),
                    __("Votre commande #:reference n'est pas encore réglée. Payez-la ou annulez-la depuis « Mes commandes » avant d'en passer une nouvelle.", ['reference' => $commande->refernce])
                );
            }

            $products = $request->input('products');

            // Avant toute ecriture et avant tout appel a la passerelle : un
            // refus plus tard laisserait une commande posee, ou pire, un
            // debit lance pour un repas que personne ne prepare.
            if ($refus = $this->refuserSiRestaurantFerme($products, 'uid')) {
                return $refus;
            }

            $adresse = $request->input('address');

            $town = Town::query()->where('slug', ! empty($adresse['town']['slug']) ? $adresse['town']['slug'] : null)->first();

            // Avant tout appel a la passerelle, comme le refus de restaurant
            // ferme juste au-dessus : un debit lance pour une livraison
            // impossible est pire qu'un refus.
            if ($refus = $this->refuserSiRestaurantTropLoin(
                $products,
                $town,
                isset($adresse['lat']) ? (float) $adresse['lat'] : null,
                isset($adresse['long']) ? (float) $adresse['long'] : null,
            )) {
                return $refus;
            }

            // $last_commande = Commande::orderBy('created_at', 'desc')->first();

            $getExistOrder = Commande::query()->nonReglee()->where('user_id', $user?->id)->first();
            $commande = $getExistOrder ?? new Commande;

            if (! $getExistOrder) {

                $refernce = $last_commande ? 1000 + $last_commande->id : 1000;
                $commande->user_id = $user->id;
                $commande->refernce = $refernce;
                $commande->status_id = 5;

            }

            $commande->price_delivery = $pricing['frais_livraison'];
            $commande->price_service = $pricing['service_price'];

            if ($total_price <= 2 && $method == 'cart') {
                return ApiResponse::BAD_REQUEST(__('Oups'), __('Error paiement'), __("Pour le paiement par cart le montant minimum c'est 2USD"));
            }

            $commande->town_id = $town->id;
            $commande->reference_adresse = ! empty($adresse['reference']) ? $adresse['reference'] : null;
            $commande->adresse_delivery = $adresse['adresse'];
            $commande->street = $adresse['street'];
            $commande->number_street = $adresse['number_street'];

            // Coordonnees choisies sur la carte : sans elles, le livreur ne
            // recoit qu'un texte libre.
            $commande->lat = $adresse['lat'] ?? null;
            $commande->long = $adresse['long'] ?? null;

            // Commande pour un tiers : le livreur doit joindre la personne a
            // livrer, pas le titulaire du compte.
            $commande->recipient_name = $request->input('recipient_name');
            $commande->recipient_phone = $request->input('recipient_phone');

            // --- Observation de la quotation serveur -------------------------
            // Ce bloc ne doit jamais modifier le comportement de valide() tant
            // que quotation.authoritative vaut false. Toute exception y est
            // absorbée : une commande ne peut pas échouer à cause de la mesure.
            $quotation = null;
            // Pourquoi la cotation n'a pas eu lieu, quand elle n'a pas eu lieu.
            $indisponibilite = null;

            try {
                $ids = [];
                $ids_by_uid = [];

                foreach ($products as $entry) {
                    $decrypted = Cipher::Decrypt($entry['uid']);

                    if ($decrypted !== false && $decrypted !== '' && ctype_digit((string) $decrypted)) {
                        $id = (int) $decrypted;
                        $ids[] = $id;
                        $ids_by_uid[$entry['uid']] = $id;
                    }
                }

                $observes = Product::query()
                    ->with('currency')
                    ->whereIn('id', $ids)
                    ->get()
                    ->keyBy('id');

                $quotation_lines = [];
                $unresolved = false;

                foreach ($products as $entry) {
                    $id = $ids_by_uid[$entry['uid']] ?? null;
                    $observed = $id !== null ? $observes->get($id) : null;

                    if ($observed) {
                        // Quantité numérique, PAS (int) : calculePrice.js fait
                        // `item.quantity * item.price` sans coercition, et les
                        // quantités arrivent ici du client sans validation
                        // d'entier. Tronquer produirait un faux écart.
                        $quotation_lines[] = [
                            'product' => $observed,
                            'quantity' => $entry['quantity'],
                        ];
                    } else {
                        $unresolved = true;
                    }
                }

                if ($unresolved) {
                    $indisponibilite = 'produit_introuvable';
                    // Une ligne non résolue chiffrerait un panier partiel : le
                    // moteur crierait à l'écart sur un panier qu'il n'a jamais
                    // vraiment vu. On ne chiffre pas, on journalise la raison.
                    Log::channel('quotation')->error('observation_impossible', [
                        'commande' => $commande->refernce,
                        'message' => 'un ou plusieurs uid de produits ne resolvent a aucun produit',
                    ]);
                } elseif ($town && $quotation_lines !== []) {
                    $quotation = app(QuotationService::class)->quote($quotation_lines, $town);

                    if (! $quotation->disponible) {
                        // Les refus du moteur (panier vide, quantité invalide,
                        // multi-restaurant, devises mélangées) n'ont AUCUN
                        // équivalent dans calculePrice.js : le client produit un
                        // nombre dans les quatre cas. Un refus a un total de 0,
                        // donc comparer les totaux ici crierait à l'écart sur
                        // chaque commande concernée. On journalise à part.
                        Log::channel('quotation')->notice('refus_quotation', [
                            'commande' => $commande->refernce,
                            'raison' => $quotation->raison,
                            'client_total' => $total_price,
                        ]);
                    } elseif (abs($quotation->total - floatval($total_price)) > 0.01) {
                        Log::channel('quotation')->warning('ecart_quotation', [
                            'commande' => $commande->refernce,
                            'client' => [
                                'total' => $total_price,
                                'frais' => $pricing['frais_livraison'] ?? null,
                                'service' => $pricing['service_price'] ?? null,
                            ],
                            'serveur' => $quotation->toArray(),
                        ]);
                    } elseif ($quotation->warnings !== []) {
                        // Les deux calculs concordent et valent tous deux 0 de
                        // frais : c'est la fuite de données delivrery_prices,
                        // pas un bug du moteur.
                        Log::channel('quotation')->info('quotation_conforme_avec_warnings', [
                            'commande' => $commande->refernce,
                            'total' => $quotation->total,
                            'warnings' => $quotation->warnings,
                        ]);
                    }
                } else {
                    $indisponibilite = $quotation_lines === [] ? 'panier_vide' : 'commune_inconnue';
                }
            } catch (Throwable $e) {
                $indisponibilite = 'erreur_technique';
                // Le canal « quotation » est peut-être précisément ce qui vient
                // d'échouer : ne jamais laisser la récupération relever.
                try {
                    Log::channel('quotation')->error('observation_impossible', [
                        'commande' => $commande->refernce,
                        'message' => $e->getMessage(),
                    ]);
                } catch (Throwable) {
                    // Rien à faire : une commande ne peut pas échouer à cause
                    // de la mesure.
                }
            }

            $autorite = (bool) config('quotation.authoritative');

            if ($autorite) {
                $refus = $this->refuserFauteDeCotation($quotation, $indisponibilite, $commande->refernce);

                if ($refus) {
                    return $refus;
                }
            }

            $serveur_fait_foi = $autorite && $quotation !== null && $quotation->disponible;

            if ($serveur_fait_foi) {
                // La ventilation suit le total. Un total du serveur avec des
                // frais du client donnerait une commande dont les lignes ne
                // font pas la somme : personne, ensuite, ne sait laquelle croire.
                $commande->price_delivery = $quotation->frais_livraison;
                $commande->price_service = $quotation->service_price;
            }

            // La devise aussi : annoncer CDF sur un panier en USD ferait
            // encaisser mille fois moins que le panier ne vaut.
            $devise_facturee = $serveur_fait_foi ? $quotation->currency : null;

            $montant_facture = $serveur_fait_foi ? $quotation->total : $total_price;
            // ----------------------------------------------------------------

            $commande->global_price = $montant_facture;

            /*
             * La section critique, et le seul endroit qui decide.
             *
             * Le controle fait en entree de methode a servi a refuser vite et
             * a afficher un message utile ; il ne prouve plus rien ici, tout
             * ce qui precede ayant pris du temps. Deux « Payer » simultanes y
             * passaient tous deux et creaient chacun leur commande, avec deux
             * references et deux montants engages aupres de FlexPay.
             *
             * La reservation est posee ici et pas plus haut pour qu'un refus
             * en amont — montant minimum par carte, panier impossible a
             * chiffrer — ne laisse pas derriere lui une commande fantome qui
             * bloquerait le client pendant tout le delai d'expiration.
             */
            if ($conflit = Commande::reserverPour($user, $commande)) {
                return ApiResponse::BAD_REQUEST(
                    ['uid' => Cipher::Encrypt($conflit->id), 'reference' => $conflit->refernce],
                    __('Commande en attente'),
                    __("Votre commande #:reference n'est pas encore réglée. Payez-la ou annulez-la depuis « Mes commandes » avant d'en passer une nouvelle.", ['reference' => $conflit->refernce])
                );
            }

            $commande->refresh();

            foreach ($products as $product) {
                // code...
                $product_id = Product::query()->find(Cipher::Decrypt($product['uid']));
                $commande_product = CommandeProduct::query()
                    ->where('user_id', $user->id)
                    ->where('product_id', $product_id->id)->where('commande_id', $commande->id)->first();
                if (! $commande_product) {
                    $commande_product = new CommandeProduct;
                }
                $commande_product->product_id = $product_id->id;
                $commande_product->price = $product_id->price;
                $commande_product->quantity = intval($product['quantity']);
                $commande_product->commande_id = $commande->id;
                $commande_product->user_id = $user->id;
                $commande_product->currency_id
                    = $devise_facturee?->id ?? $pricing['currency']['id'];
                $commande_product->save();
            }

            $user_name = auth()->user()->name;
            $user_email = auth()->user()->email;

            if ($method != 'cart') {
                $phone_check = new LibPhoneNumber($phone);

                if (! $phone_check->checkValidationNumber()) {
                    return ApiResponse::BAD_REQUEST('Oups', 'Numéro de téléphone invalide', 'Mpesa');
                }
            }

            $data = [
                'amount' => floatval($montant_facture),
                'phone' => $phone,
                'name' => $user_name,
                'email' => $user_email,
                'currency' => $devise_facturee?->code
                    ?? (! empty($pricing['currency']['code']) ? $pricing['currency']['code'] : 'CDF'),
                'reference' => $commande->refernce,
                'callback_url' => config('flexpay.callback_url'),
                'approve_url' => $success_url,
                'cancel_url' => $cancle_url,
                'decline_url' => $error_url,
                'language' => 'fr',
                'description' => 'Paiement facture thalia eats',
            ];

            $result = FlexPay::sendData($data, $method);

            if (! empty($result['code']) && $result['code'] != 0) {

                return ApiResponse::BAD_REQUEST('Oups', 'Erreur', $result['message']);

            }

            $commande->reference_paiement = $result['orderNumber'];
            $commande->code_confirmation = rand(1000, 9999);
            $commande->code_confirmation_restaurant = rand(1000, 9999);
            $commande->save();

            $status_paiement = StatusPayement::query()->where('is_default', true)->first();

            Payement::query()->updateOrCreate([
                'commande_id' => $commande->id,
                'phone' => preg_replace('/[\s+]/', '', $phone),
                'channel' => 'MPESA',
            ], [
                'code' => $result['code'],
                'commande_id' => $commande->id,
                'phone' => preg_replace('/[\s+]/', '', $phone),
                'channel' => 'MPESA',
                'status_payement_id' => $status_paiement?->id,
                'amount' => $montant_facture,
                'amount_customer' => $montant_facture,
                'webhook_sse_url' => $webhook_url,
            ]);

            $body = [
                'action' => 'paiement-check',
                'status' => $status_paiement,
            ];

            $user = auth()->user();
            if ($user->expo_push_token) {
                $push = new FirebasePushNotification;
                $push->sendPushNotification(auth()->user()->expo_push_token, 'paiemnt', json_encode($body));
                $this->diffuserPaiement($result['orderNumber']);

                FirebasePushNotification::sendNotification($user->expo_push_token, 'Paiment', $result['message']);
            }

            return ApiResponse::SUCCESS_DATA($result, 'Save', $result['message']);

        } catch (Exception $e) {

            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function verif_paiement(Request $request)
    {
        try {
            $uid = $request->input('uid');
            $user = Auth()->user();

            $validator = Validator::make($request->all(), [
                'uid' => 'required|string',
            ]);

            if ($validator->fails()) {
                return ApiResponse::BAD_REQUEST('Oups', 'Error', $validator->errors());
            }

            $commande = Commande::query()->where('id', Cipher::Decrypt($uid))
                ->where('status_id', '>', 1)
                ->where('user_id', $user->id)->first();
            if (! $commande) {
                return ApiResponse::BAD_REQUEST('Oups', 'Erreur', 'Erreur du paiement');
            }

            if ($commande->status_id == 2) {
                return ApiResponse::SUCCESS_DATA('', 'Success', 'Merci pour votre confiance');
            }

            return ApiResponse::SUCCESS_DATA('', 'Success', 'Commande déjà traitée');

        } catch (Exception $e) {

            return ApiResponse::SERVER_ERROR($e);
        }

    }

    public function paiement(Request $request, $uid)
    {
        try {
            /*
             * Trois clauses la ou il n'y en avait qu'une.
             *
             * - `user_id` : l'uid n'est pas une capacite, Cipher chiffre un
             *   entier avec une cle du depot et sans MAC. Sans ce filtre, tout
             *   compte authentifie pouvait envoyer la commande d'autrui a la
             *   passerelle et en lire le montant ;
             * - `nonReglee()` : le `where('status_id', 5)` nu laissait passer
             *   une commande annulee ou perimee, dont la colonne est restee a
             *   5. Le client etait debite pour une commande morte. C'est la
             *   definition partagee, celle que lisent les ecrans ;
             * - le statut, nomme : seule une commande deja envoyee au paiement
             *   se regle ici, pas un panier en cours de composition.
             */
            $order = Commande::query()->where('id', Cipher::Decrypt($uid))
                ->where('user_id', auth()->id())
                ->nonReglee()
                ->where('status_id', Commande::STATUT_ATTENTE_PAIEMENT)
                ->first();
            if (! $order) {
                return ApiResponse::BAD_REQUEST(__('Oups'), __('Error'), __('Commande invalide déjà payer ou annuler'));
            }

            $success_url = $request->input('success_url');
            $error_url = $request->input('error_url');
            $cancle_url = $request->input('cancel_url');
            // Cette adresse est appelee par le serveur depuis le webhook de
            // paiement, qui est public. La laisser venir de la requete
            // revenait a offrir un POST vers l'hote de son choix — metadonnees
            // cloud, services internes, proxy Ollama en 127.0.0.1:11434.
            $webhook_url = config('sse.webhook_url');
            $phone = $request->input('phone');
            $method = $request->input('method', 'mobile');
            $mobile = $request->input('mobile');

            $user_name = auth()->user()->name;
            $user_email = auth()->user()->email;
            $user = auth()->user();
            $user->phone = $mobile;
            $user->save();

            if ($method != 'cart') {
                $phone_check = new LibPhoneNumber($phone);

                if (! $phone_check->checkValidationNumber()) {
                    return ApiResponse::BAD_REQUEST('Oups', 'Numéro de téléphone invalide', 'Mpesa');
                }
            }

            $data = [
                'amount' => floatval($order->global_price),
                'phone' => $phone,
                'name' => $user_name,
                'email' => $user_email,
                'currency' => ! empty($order->product) ? $order->product[0]->currency->code : 'CDF',
                'reference' => $order->refernce,
                // Le client ne decide pas ou son paiement est confirme : une
                // adresse fournie par l'appelant enverrait la confirmation
                // ailleurs que sur l'instance qui detient la commande.
                'callback_url' => config('flexpay.callback_url'),
                'approve_url' => $success_url,
                'cancel_url' => $cancle_url,
                'decline_url' => $error_url,
                'language' => 'fr',
                'description' => 'Paiement facture thalia eats',
            ];

            $result = FlexPay::sendData($data, $method);

            if (! empty($result['code']) && $result['code'] != 0) {

                return ApiResponse::BAD_REQUEST('Oups', 'Erreur', $result['message']);

            }

            $order->reference_paiement = $result['orderNumber'];
            $order->code_confirmation = rand(1000, 9999);
            $order->code_confirmation_restaurant = rand(1000, 9999);
            $order->save();

            $status_paiement = StatusPayement::query()->where('is_default', true)->first();

            Payement::query()->updateOrCreate([
                'commande_id' => $order->id,
                'phone' => preg_replace('/[\s+]/', '', $phone),
                'channel' => 'MPESA',
            ], [
                'code' => $result['code'],
                'commande_id' => $order->id,
                'phone' => preg_replace('/[\s+]/', '', $phone),
                'channel' => 'MPESA',
                'status_payement_id' => $status_paiement?->id,
                'amount' => $order->global_price,
                'amount_customer' => $order->global_price,
                'webhook_sse_url' => $webhook_url,
            ]);

            $body = [
                'action' => 'paiement-check',
                'status' => $status_paiement,
            ];

            $user = auth()->user();

            if ($user->expo_push_token) {

                $push = new FirebasePushNotification;
                $push->sendPushNotification(auth()->user()->expo_push_token, 'paiement', json_encode($body));
                $this->diffuserPaiement($result['orderNumber']);

                FirebasePushNotification::sendNotification($user->expo_push_token, 'Paiement', $result['message']);
            }

            return ApiResponse::SUCCESS_DATA($result, 'Save', $result['message']);

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    /**
     * Diffuse l'evenement de paiement en temps reel sans jamais faire
     * echouer le paiement. Une panne Pusher (404 app/host/cluster, reseau,
     * identifiants absents en prod) ne doit pas casser une transaction deja
     * encaissee : on journalise et on continue. Le temps reel est un confort,
     * pas une condition du paiement.
     */
    private function diffuserPaiement($orderNumber): void
    {
        try {
            event(new PayementEvent($orderNumber));
        } catch (Throwable $e) {
            logger()->warning('Diffusion paiement Pusher echouee : '.$e->getMessage());
        }
    }
}
