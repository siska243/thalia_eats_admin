<?php

namespace App\Http\Controllers\Api;

use App\Enums\CallBackEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommandeRequest;
use App\Http\Resources\CommandeResource;
use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Product;
use App\Models\Town;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use App\Wrappers\EasyPay;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

            $town = Town::where('id', Cipher::Decrypt($adresse['town']['uid']))->first();

            $user = auth()->user();
            $last_commande = Commande::orderBy('created_at', 'desc')->first();
            $commande = Commande::where('status_id', 1)->where('user_id', $user->id)->first();


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

            foreach ($products as $product) {
                # code...
                $product_id = Product::find(Cipher::Decrypt($product['uid']));
                $commande_product = CommandeProduct::where('product_id', $product_id->id)->where('commande_id', $commande->id)->first();
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
            // $user_name = auth()->user()->name;
            // $user_email = auth()->user()->email;

            // session(['callback_success_url',$url['success']]);
            // session(['callback_error_url',$url['error']]);
            // session(['callback_cancel_url',$url['cancel']]);

            // $result = EasyPay::SEND_DATA(
            //     $commande->refernce,
            //                     $globale_price + $commande->price_delivery + $commande->price_service,
            //     $pricing['currency']['code'],
            //     'commande',
            //     $user_name,
            //     $user_email,
            //     route('callback',['action'=>CallBackEnum::SUCCESS->value]),
            //     route('callback',['action'=>CallBackEnum::ERROR->value]),
            //     route('callback',['action'=>CallBackEnum::CANCEL->value]),
            // );
            // if ($result['code'] == 1) {

            //     $commande->reference_paiement = $result['reference'];



            // }
            $commande->save();
            return ApiResponse::SUCCESS_DATA(new CommandeResource($commande), 'Commande ajouter', 'La commande a été ajouter avec succès');

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
            $commande = Commande::with('product')->where('status_id', 2)->where('user_id', $user->id)->first();

            return ApiResponse::GET_DATA(new CommandeResource($commande));
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function traitement()
    {
        try {

            $user = Auth()->user();
            $commande = Commande::with('product')->where('status_id', 2)->where('user_id', $user->id)->first();

            return ApiResponse::GET_DATA(new CommandeResource($commande));
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function show($refernce)
    {
        $commandes = Commande::with('product')->where('refernce', $refernce)->first();
        return ApiResponse::GET_DATA(new CommandeResource($commandes));
    }
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function valide(CommandeRequest $request)
    {
        try {

            $products = $request->products;
            $pricing = $request->pricing;
            $adresse = $request->adresse;

            $town = Town::where('id', Cipher::Decrypt($adresse['town']['uid']))->first();

            $user = auth()->user();
            // $last_commande = Commande::orderBy('created_at', 'desc')->first();
            $commande = Commande::where('status_id', 1)->where('user_id', $user->id)->first();

            if(!$commande) return ApiResponse::NOT_FOUND("Oups","Cette commande est introuvable");

            $commande->price_delivery = $pricing['frais_livraison'];
            $commande->price_service = $pricing['service_price'];
            $commande->town_id = $town->id;
            $commande->reference_adresse = $adresse['reference'] ? $adresse['reference'] : null;
            $commande->adresse_delivery = $adresse['adresse'];
            $commande->status_id = 2;
            $globale_price = 0;

            foreach ($products as $product) {
                # code...
                $product_id = Product::find(Cipher::Decrypt($product['uid']));
                $commande_product = CommandeProduct::where('product_id', $product_id->id)->where('commande_id', $commande->id)->first();
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

            $commande->code_confirmation = rand(1000, 9999);
            $commande->code_confirmation_restaurant = rand(1000, 9999);

            $commande->reference_paiement = "THALIA-EATS-".rand(1000, 9999).'-'.now()->format('Y');
            $commande->save();

            return ApiResponse::SUCCESS_DATA(new CommandeResource($commande), 'Commande effectuée', 'La commande a été effectuée avec succès');

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }
}
