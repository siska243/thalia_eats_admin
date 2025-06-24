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
            $commande = Commande::query()->whereIn('status_id', [1, 5])->where('user_id', $user->id)->first();

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
            $commande = Commande::query()->whereIn('status_id', [1, 5])->where('user_id', $user->id)->first();


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

    public function updateDeliveryAddress(Request $request)
    {
        try {

            $town_id = $request->input("town");
            $reference = $request->input("reference");
            $street = $request->input("street");
            $number_street = $request->input("number_street");

            $town_id = Town::query()->where('slug', $town_id)->first()?->id;
            $user = Auth()->user();

            $commande = Commande::with('product')->whereIn('status_id', [1, 5])->where('user_id', $user?->id)->first();

            $commande->town_id = $town_id;
            $commande->reference_adresse = $reference;
            $commande->adresse_delivery = "{$street} {$number_street} {$reference}";
            $commande->street = $street;
            $commande->number_street = $number_street;

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
            $commande = Commande::with('product')->whereIn('status_id', [1, 5])
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

            $commande = Commande::with('product')->whereIn('status_id', [1, 5])->where('user_id', $user?->id)->first();

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

            $commande = Commande::with(['product', 'delivrery_driver', 'status'])->whereIn('status_id', [2])->where('user_id', $user->id)->get();

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
            $commande = Commande::with('product')->whereIn('status_id', [1, 5])->where('user_id', $user?->id)->first();
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

            $commande = Commande::with(['product', 'delivrery_driver', 'status'])->whereIn('status_id', [2, 5])
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

    public function showOrder(string $uid)
    {
        $commande = Commande::with(['product', 'delivrery_driver', 'status'])
            ->where('id', Cipher::Decrypt($uid))
            ->first();

        if (!$commande) {
            return ApiResponse::GET_DATA(Cipher::Decrypt($uid));
        }
        return ApiResponse::GET_DATA($commande ? new CommandeResource($commande) : null);
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

            $current_order = Commande::query()->where('id', Cipher::Decrypt($order))->where('status_id', 2)->first();

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

            $data = TrackOrder::query()->where('commande_id', Cipher::Decrypt($uid))->first();
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

                        if ($user->expo_push_token) {
                            $push = new FirebasePushNotification();
                            $push->sendPushNotification($user->expo_push_token, "Nouvelle commande", json_encode($body));
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
            $callback_url = $request->input('callback_url');
            $webhook_url = $request->input('webhook_sse_url');
            $pricing = $request->input('pricing');
            $phone = $request->input('phone');
            $method = $request->input('method', 'mobile');
            $total_price = $request->input('total_price');

            $user = auth()->user();

            if (!$user) {
                return ApiResponse::NOT_AUTHORIZED();
            }

            $last_commande = Commande::query()->orderBy('created_at', 'desc')->first();
            $commande = Commande::query()->whereIn('status_id', [1,5])->where('user_id', $user?->id)->first();

            if ($commande) {
                return ApiResponse::BAD_REQUEST(__(""), __("Oups"), __("Vous avez déjà de commande en cours, veuillez finaliser le paiement."));
            }

            $products = $request->input("products");

            $adresse = $request->input("address");

            $town = Town::query()->where('slug', !empty($adresse['town']['slug']) ? $adresse['town']['slug'] : null)->first();


            // $last_commande = Commande::orderBy('created_at', 'desc')->first();

            $getExistOrder = Commande::query()->whereIn('status_id', [1, 5])->where('user_id', $user?->id)->first();
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

            $commande->global_price = $total_price;
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
                'amount' => floatval($total_price),
                'phone' => $phone,
                'name' => $user_name,
                'email' => $user_email,
                'currency' => !empty($pricing['currency']['code']) ? $pricing['currency']['code'] : "CDF",
                'reference' => $commande->refernce,
                'callback_url' => $callback_url,
                'approve_url' => $success_url,
                'cancel_url' => $cancle_url,
                "decline_url" => $error_url,
                'language' => "fr",
                'description' => "Paiement facture thalia eats",
            ];

            $result = FlexPay::sendData($data, $method);

            Log::info(json_encode($result));

            if ($result['code'] != 0) {

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
                'amount' => $total_price,
                'amount_customer' => $total_price,
                'webhook_sse_url' => $webhook_url
            ]);

            $body = [
                'action' => 'paiement-check',
                'status' => $status_paiement,
            ];

            $push = new FirebasePushNotification();
            $push->sendPushNotification(auth()->user()->expo_push_token, 'paiemnt', json_encode($body));
            event(new PayementEvent($result['orderNumber']));

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
            $callback_url = $request->input('callback_url');
            $webhook_url = $request->input('webhook_sse_url');
            $phone = $request->input('phone');
            $method = $request->input('method', 'mobile');


            $user_name = auth()->user()->name;
            $user_email = auth()->user()->email;

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
                'callback_url' => $callback_url,
                'approve_url' => $success_url,
                'cancel_url' => $cancle_url,
                "decline_url" => $error_url,
                'language' => "fr",
                'description' => "Paiement facture thalia eats",
            ];

            $result = FlexPay::sendData($data, $method);

            Log::info(json_encode($result));

            if ($result['code'] != 0) {

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

            $push = new FirebasePushNotification();
            $push->sendPushNotification(auth()->user()->expo_push_token, 'paiement', json_encode($body));
            event(new PayementEvent($result['orderNumber']));

            return ApiResponse::SUCCESS_DATA($result, "Save", $result['message']);


        } catch (\Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }
}
