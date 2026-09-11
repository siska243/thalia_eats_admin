<?php

namespace App\Http\Controllers\Api;

use App\Enums\CallBackEnum;
use App\Events\PayementEvent;
use App\Helpers\CurrentHelpers;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommandeRequest;
use App\Http\Resources\CommandeResource;
use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Payement;
use App\Models\Product;
use App\Models\StatusPayement;
use App\Models\Town;
use App\Models\TrackOrder;
use App\Services\QuotationService;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use App\Wrappers\EasyPay;
use App\Wrappers\FirebasePushNotification;
use App\Wrappers\FlexPay;
use App\Wrappers\Geocode;
use App\Wrappers\LibPhoneNumber;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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

            $town = Town::query()->where('id', Cipher::Decrypt($adresse['town']['uid']))->first();

            $user = auth()->user();

            $last_commande = Commande::query()->orderBy('created_at', 'desc')->first();
            $commande = Commande::query()->nonReglee()->where('user_id', $user->id)->first();

            if (!$commande) {

                $commande = new Commande();
                $refernce = $last_commande ? 1000 + $last_commande->id : 1000;
                $commande->user_id = $user->id;
                $commande->refernce = $refernce;
                $commande->status_id = 1;

            }


            $commande->price_delivery = $pricing['frais_livraison'];
            $commande->price_service = $pricing['service_price'];

            $commande->town_id = $town->id;
            $commande->reference_adresse = !empty($adresse['reference']) ? $adresse['reference'] : null;
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
            $globale_price = 0;

            if (!empty($products)) {
                return ApiResponse::BAD_REQUEST(__('Oups'), __("Error"), __("Veuillez ajouter au moins un produits"));
            }

            foreach ($products as $product) {
                # code...
                $product_id = Product::query()->find(Cipher::Decrypt($product['uid']));
                $commande_product = CommandeProduct::query()
                    ->where("user_id", $user->id)
                    ->where('product_id', $product_id->id)->where('commande_id', $commande->id)->first();
                if (!$commande_product) $commande_product = new CommandeProduct();
                $commande_product->product_id = $product_id->id;
                $commande_product->price = $product_id->price;
                $commande_product->quantity = intval($product['quantity']);
                $commande_product->commande_id = $commande->id;
                $commande_product->user_id = $user->id;
                $commande_product->currency_id
                    = $pricing['currency']['id'];
                $globale_price += $commande_product->price * $commande_product->quantity;
                $commande_product->save();
            }

            $commande->global_price = $globale_price;

            $commande->save();

            return ApiResponse::SUCCESS_DATA(new CommandeResource($commande), 'Commande ajouter', 'La commande a été ajouter avec succès');

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function addProduct(Request $request)
    {

        try {
            $products = $request->input("products");

            $user = auth()->user();
            $last_commande = Commande::query()->orderBy('created_at', 'desc')->first();
            $commande = Commande::query()->nonReglee()->where('user_id', $user->id)->first();


            if (!$commande) {

                $commande = new Commande();
                $refernce = $last_commande ? 1000 + $last_commande->id : 1000;
                $commande->user_id = $user->id;
                $commande->refernce = $refernce;
                $commande->status_id = 1;

            }

            if (count($products) > 0) {
                foreach ($products as $product) {


                    $product_id = Product::query()->find(Cipher::Decrypt($product['product_id']));
                    $commande_product = CommandeProduct::query()->where('product_id', $product_id->id)
                        ->where("user_id", $user->id)
                        ->where('commande_id', $commande->id)->first();
                    if (!$commande_product) $commande_product = new CommandeProduct();

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


            $commande_product = CommandeProduct::query()->where('product_id', $product_id->id)->where('commande_id', $commande->id)->get();
            $sum = 0;
            collect($commande_product)->each(function ($commande_product) use (&$sum) {

                $sum = $commande_product->price * $commande_product->quantity;
            });

            $commande->global_price = $sum;
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
                    "Indiquez une adresse et une commune desservie"
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

            if (!$commande) {
                return ApiResponse::NOT_FOUND(
                    'Oups',
                    "Aucune commande en attente ne correspond"
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
            $commandId = $request->input("uid");

            if (!$commandId) {
                return ApiResponse::BAD_REQUEST(__("Error"), __("Oups"), __("Commande is required"));
            }
            $commande = Commande::with('product')->nonReglee()
                ->where('id', Cipher::Decrypt($commandId))
                ->where('user_id', $user?->id)
                ->latest()
                ->first();

            if (!$commande) {
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

            if (!$commande) {
                return ApiResponse::NOT_FOUND(__("Not found"), __('messages.commandes.not_found'));
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

            $commande = Commande::with(['product', 'delivrery_driver', 'status'])
                ->whereIn('status_id', [2, 5])
                ->nonAnnulee()
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

    public function show($refernce)
    {
        $commande = Commande::with(['product', 'delivrery_driver', 'status'])->where('refernce', $refernce)->first();
        return ApiResponse::GET_DATA($commande ? new CommandeResource($commande) : null);
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

        if ($id === false || !ctype_digit((string) $id)) return null;

        $user = auth()->user();

        if (!$user) return null;

        return Commande::query()
            ->with($with)
            ->where('id', (int) $id)
            ->where(function ($query) use ($user) {
                $query
                    ->where('user_id', $user->id)
                    ->orWhereHas('delivrery_driver', fn ($q) => $q->where('user_id', $user->id))
                    ->orWhereHas(
                        'commande_products.product.restaurant',
                        fn ($q) => $q->where('user_id', $user->id)
                    );
            })
            ->first();
    }

    public function showOrder(string $uid)
    {
        $commande = $this->commandeAccessible($uid, ['product', 'delivrery_driver', 'status']);

        // Un uid inconnu et un uid appartenant a autrui donnent la meme
        // reponse : sinon l'ecart renseigne sur l'existence des commandes.
        // L'ancienne version renvoyait en plus l'identifiant entier decode.
        if (!$commande) {
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

            if (!$order) {
                return ApiResponse::BAD_REQUEST(__("Oups"), __("error"), __('messages.commandes.not_found'));
            }

            if (!$location) {
                return ApiResponse::BAD_REQUEST(__("Oups"), __("error"), __('messages.commandes.not_found'));
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

                $address = "{$current_order->adresse_delivery}, {$current_order->town?->title}";

                $track = TrackOrder::query()->where('commande_id', $current_order->id)->first();
                if ($track) {
                    TrackOrder::query()->create([
                        'commande_id' => $current_order->id,
                        'location_customer' => $track->location_customer,
                        'location_delivery' => $location
                    ]);
                } else {
                    $geo_code = Geocode::getLatLngByAddress($address);
                    TrackOrder::query()->create([
                        'commande_id' => $current_order->id,
                        'location_customer' => $geo_code,
                        'location_delivery' => $location
                    ]);
                }
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

            if (!$commande) return ApiResponse::NOT_FOUND('Oups', 'Commande introuvable');

            $data = TrackOrder::query()->where('commande_id', $commande->id)->first();

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
            $result = FlexPay::checkPaiement($orderNumber);
            if ($result['code'] != 0) {

                return ApiResponse::BAD_REQUEST("Oups!!", $result['title'], $result['message']);
            } else {

                $status = $result['transaction']['status'];

                $status_paiement = StatusPayement::query()->where('code', $status)->first();

                if ($status_paiement?->is_paid) {

                    $order = Commande::query()
                        ->with('user')
                        ->where('refernce', $result['transaction']['reference'])->first();

                    $order->status_id = 2;
                    $order->reference_paiement = $result['transaction']['provider_reference'];
                    //envoyer la commande au restaurateur
                    if (!$order->paied_at) {
                        $order->paied_at = now()->format("Y-m-d H:i:s");

                        $user = CurrentHelpers::getUserByOrder($order);

                        $body = [
                            'action' => 'new-order',
                            'status' => $status_paiement,
                        ];

                        if ($user->expo_push_token) {
                            $push = new FirebasePushNotification();
                            $push->sendPushNotification($user->expo_push_token, "Nouvelle commande", json_encode($body));

                            FirebasePushNotification::sendNotification($user->expo_push_token, "Thalia eats commande", "Nouvelle commande");
                        }
                    }

                    $order->save();

                }

                return ApiResponse::GET_DATA($status_paiement);
            }
        } catch (\Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
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
            $mobile=$request->input('mobile');

            $user = auth()->user();
            $user->phone = $mobile;

            if (!$user) {
                return ApiResponse::NOT_AUTHORIZED();
            }

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

            $products = $request->input("products");

            $adresse = $request->input("address");

            $town = Town::query()->where('slug', !empty($adresse['town']['slug']) ? $adresse['town']['slug'] : null)->first();


            // $last_commande = Commande::orderBy('created_at', 'desc')->first();

            $getExistOrder = Commande::query()->nonReglee()->where('user_id', $user?->id)->first();
            $commande = $getExistOrder ?? new Commande();

            if (!$getExistOrder) {

                $refernce = $last_commande ? 1000 + $last_commande->id : 1000;
                $commande->user_id = $user->id;
                $commande->refernce = $refernce;
                $commande->status_id = 5;


            }

            $commande->price_delivery = $pricing['frais_livraison'];
            $commande->price_service = $pricing['service_price'];

            if ($total_price <= 2 && $method == "cart") {
                return ApiResponse::BAD_REQUEST(__('Oups'), __("Error paiement"), __("Pour le paiement par cart le montant minimum c'est 2USD"));
            }

            $commande->town_id = $town->id;
            $commande->reference_adresse = !empty($adresse['reference']) ? $adresse['reference'] : null;
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
                }
            } catch (\Throwable $e) {
                // Le canal « quotation » est peut-être précisément ce qui vient
                // d'échouer : ne jamais laisser la récupération relever.
                try {
                    Log::channel('quotation')->error('observation_impossible', [
                        'commande' => $commande->refernce,
                        'message' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {
                    // Rien à faire : une commande ne peut pas échouer à cause
                    // de la mesure.
                }
            }

            $montant_facture = (config('quotation.authoritative') && $quotation !== null && $quotation->disponible)
                ? $quotation->total
                : $total_price;
            // ----------------------------------------------------------------

            $commande->global_price = $montant_facture;
            $commande->save();

            $commande->refresh();

            foreach ($products as $product) {
                # code...
                $product_id = Product::query()->find(Cipher::Decrypt($product['uid']));
                $commande_product = CommandeProduct::query()
                    ->where("user_id", $user->id)
                    ->where('product_id', $product_id->id)->where('commande_id', $commande->id)->first();
                if (!$commande_product) $commande_product = new CommandeProduct();
                $commande_product->product_id = $product_id->id;
                $commande_product->price = $product_id->price;
                $commande_product->quantity = intval($product['quantity']);
                $commande_product->commande_id = $commande->id;
                $commande_product->user_id = $user->id;
                $commande_product->currency_id
                    = $pricing['currency']['id'];
                //$globale_price += $commande_product->price * $commande_product->quantity;
                $commande_product->save();
            }


            $user_name = auth()->user()->name;
            $user_email = auth()->user()->email;

            if ($method != "cart") {
                $phone_check = new LibPhoneNumber($phone);

                if (!$phone_check->checkValidationNumber()) {
                    return ApiResponse::BAD_REQUEST("Oups", "Numéro de téléphone invalide", "Mpesa");
                }
            }


            $data = [
                'amount' => floatval($montant_facture),
                'phone' => $phone,
                'name' => $user_name,
                'email' => $user_email,
                'currency' => !empty($pricing['currency']['code']) ? $pricing['currency']['code'] : "CDF",
                'reference' => $commande->refernce,
                'callback_url' => config('flexpay.callback_url'),
                'approve_url' => $success_url,
                'cancel_url' => $cancle_url,
                "decline_url" => $error_url,
                'language' => "fr",
                'description' => "Paiement facture thalia eats",
            ];

            $result = FlexPay::sendData($data, $method);


            if (!empty($result['code']) && $result['code'] != 0) {

                return ApiResponse::BAD_REQUEST('Oups', 'Erreur', $result["message"]);

            }

            $commande->reference_paiement = $result['orderNumber'];
            $commande->code_confirmation = rand(1000, 9999);
            $commande->code_confirmation_restaurant = rand(1000, 9999);
            $commande->save();


            $status_paiement = StatusPayement::query()->where('is_default', true)->first();

            Payement::query()->updateOrCreate([
                'commande_id' => $commande->id,
                'phone' => preg_replace('/[\s+]/', '', $phone),
                'channel' => "MPESA",
            ], [
                'code' => $result['code'],
                'commande_id' => $commande->id,
                'phone' => preg_replace('/[\s+]/', '', $phone),
                'channel' => "MPESA",
                'status_payement_id' => $status_paiement?->id,
                'amount' => $montant_facture,
                'amount_customer' => $montant_facture,
                'webhook_sse_url' => $webhook_url
            ]);

            $body = [
                'action' => 'paiement-check',
                'status' => $status_paiement,
            ];

            $user=auth()->user();
            if($user->expo_push_token){
                $push = new FirebasePushNotification();
                $push->sendPushNotification(auth()->user()->expo_push_token, 'paiemnt', json_encode($body));
                event(new PayementEvent($result['orderNumber']));

                FirebasePushNotification::sendNotification($user->expo_push_token,"Paiment", $result['message']);;
            }


            return ApiResponse::SUCCESS_DATA($result, "Save", $result['message']);

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
                return ApiResponse::BAD_REQUEST("Oups", "Error", $validator->errors());
            }


            $commande = Commande::query()->where('id', Cipher::Decrypt($uid))
                ->where('status_id', '>', 1)
                ->where('user_id', $user->id)->first();
            if (!$commande) {
                return ApiResponse::BAD_REQUEST("Oups", "Erreur", "Erreur du paiement");
            }

            if ($commande->status_id == 2) {
                return ApiResponse::SUCCESS_DATA("", "Success", "Merci pour votre confiance");
            }


            return ApiResponse::SUCCESS_DATA("", "Success", "Commande déjà traitée");

        } catch (Exception $e) {

            return ApiResponse::SERVER_ERROR($e);
        }

    }

    public function paiement(Request $request, $uid)
    {
        try {
            $order = Commande::query()->where('id', Cipher::Decrypt($uid))
                ->where('status_id', 5)
                ->first();
            if (!$order) {
                return ApiResponse::BAD_REQUEST(__("Oups"), __("Error"), __("Commande invalide déjà payer ou annuler"));
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
            $mobile=$request->input('mobile');


            $user_name = auth()->user()->name;
            $user_email = auth()->user()->email;
            $user = auth()->user();
            $user->phone = $mobile;
            $user->save();

            if ($method != "cart") {
                $phone_check = new LibPhoneNumber($phone);

                if (!$phone_check->checkValidationNumber()) {
                    return ApiResponse::BAD_REQUEST("Oups", "Numéro de téléphone invalide", "Mpesa");
                }
            }


            $data = [
                'amount' => floatval($order->global_price),
                'phone' => $phone,
                'name' => $user_name,
                'email' => $user_email,
                'currency' => !empty($order->product) ? $order->product[0]->currency->code : "CDF",
                'reference' => $order->refernce,
                // Le client ne decide pas ou son paiement est confirme : une
                // adresse fournie par l'appelant enverrait la confirmation
                // ailleurs que sur l'instance qui detient la commande.
                'callback_url' => config('flexpay.callback_url'),
                'approve_url' => $success_url,
                'cancel_url' => $cancle_url,
                "decline_url" => $error_url,
                'language' => "fr",
                'description' => "Paiement facture thalia eats",
            ];

            $result = FlexPay::sendData($data, $method);


            if (!empty($result['code']) && $result['code'] != 0) {

                return ApiResponse::BAD_REQUEST('Oups', 'Erreur', $result["message"]);

            }

            $order->reference_paiement = $result['orderNumber'];
            $order->code_confirmation = rand(1000, 9999);
            $order->code_confirmation_restaurant = rand(1000, 9999);
            $order->save();


            $status_paiement = StatusPayement::query()->where('is_default', true)->first();

            Payement::query()->updateOrCreate([
                'commande_id' => $order->id,
                'phone' => preg_replace('/[\s+]/', '', $phone),
                'channel' => "MPESA",
            ], [
                'code' => $result['code'],
                'commande_id' => $order->id,
                'phone' => preg_replace('/[\s+]/', '', $phone),
                'channel' => "MPESA",
                'status_payement_id' => $status_paiement?->id,
                'amount' => $order->global_price,
                'amount_customer' => $order->global_price,
                'webhook_sse_url' => $webhook_url
            ]);

            $body = [
                'action' => 'paiement-check',
                'status' => $status_paiement,
            ];

            $user=auth()->user();

            if($user->expo_push_token){

                $push = new FirebasePushNotification();
                $push->sendPushNotification(auth()->user()->expo_push_token, 'paiement', json_encode($body));
                event(new PayementEvent($result['orderNumber']));

                FirebasePushNotification::sendNotification($user->expo_push_token,"Paiement",$result['message']);
            }


            return ApiResponse::SUCCESS_DATA($result, "Save", $result['message']);


        } catch (\Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }
}
