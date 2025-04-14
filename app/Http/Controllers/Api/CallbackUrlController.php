<?php

namespace App\Http\Controllers\Api;

use App\Enums\CallBackEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\DelivreryPriceResource;
use App\Http\Resources\TownResource;
use App\Models\DelivreryPrice;
use App\Models\Town;
use App\Wrappers\ApiResponse;
use Illuminate\Http\Request;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class CallbackUrlController extends Controller
{
    //
    public function index(Request $request, $action){


        switch ($action) {
            case CallBackEnum::SUCCESS->value:
                # code...
                $mobileAppUrl=session('callback_success_url');
                break;
            case CallBackEnum::CANCEL->value:
                $mobileAppUrl=session('callback_cancel_url');
                break;
            default:
                # code...
                $mobileAppUrl=session('callback_error_url');
                break;
        }

        $redirectUrl = url($mobileAppUrl);

        return redirect($redirectUrl);
    }

    public function paiement(Request $request)
    {

        try{
            Stripe::setApiKey(CallBackEnum::SK_KEY->value);

            $intent = PaymentIntent::create([
                'amount' => 1099,
                'currency' => 'usd',
                'payment_method_types' => ['card'],
                'return_url'=>$request->input('url')
              ]);
            $client_secret = $intent->client_secret;
            return ApiResponse::GET_DATA(['clientSecret' => $client_secret]);

        }
        catch(\Exception $e){
            return ApiResponse::SERVER_ERROR($e);
        }


    }
}
