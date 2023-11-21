<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CommandeRequest;
use App\Http\Resources\CommandeResource;
use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Product;
use App\Models\Town;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
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
            $commande = Commande::with('product')->where('status_id','!=', 1)->where('user_id', $user->id)->get();

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
            $town = Town::where('id', Cipher::Decrypt($adresse['town']['uid']))->first();

            $user = auth()->user();
            $last_commande = Commande::orderBy('created_at', 'desc')->first();
            $commande = Commande::where('status_id', 1)->where('user_id', $user->id)->first();

            if (!$commande) {
                $commande = new Commande();
                $refernce = '#' . ($last_commande ? 1000 + $last_commande->id : 1000);
                $commande->user_id = $user->id;
                $commande->refernce = $refernce;
                $commande->status_id = 1;
                $commande->code_confirmation = rand(1000, 9999);
                $commande->code_confirmation_restaurant = rand(1000, 9999);
            }

            $commande->price_delivery = $pricing['frais_livraison'];
            $commande->price_service = $pricing['service_price'];
            $commande->town_id = $town->id;
            $commande->reference_adresse = $adresse['reference'] ? $adresse['reference'] : null;
            $commande->adresse_delivery = $adresse['adresse'];
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
    public function show()
    {
        try {

            $user = Auth()->user();
            $commande = Commande::with('product')->where('status_id', 1)->where('user_id', $user->id)->first();

            return ApiResponse::GET_DATA(new CommandeResource($commande));
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
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
}
