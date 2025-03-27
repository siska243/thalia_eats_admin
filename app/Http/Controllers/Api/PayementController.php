<?php

namespace App\Http\Controllers\Api;

use App\Events\PayementEvent;
use App\Helpers\CurrentHelpers;
use App\Http\Controllers\Controller;

use App\Http\Resources\CommandeResource;
use App\Models\Commande;
use App\Models\Payement;
use App\Models\StatusPayement;
use App\Wrappers\ApiResponse;
use App\Wrappers\FirebasePushNotification;
use App\Wrappers\FlexPay;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayementController extends Controller
{

    public function webhook(Request $request){
        try{

            $payload = $request->all();

            // Log des données pour tester
            Log::info('Webhook reçu:', $payload);

            $reference=$request->input('reference');
            $amount=$request->input('amount');
            $amountCustomer=$request->input('amountCustomer');
            $channel=$request->input('channel');
            $orderNumber=$request->input('orderNumber');
            $code=$request->input('code');
            $phone=$request->input('phone');
            $provider_reference=$request->input('provider_reference');

            $order=Commande::query()
                ->with('user')
                ->where('refernce',$reference)->first();



            $result=FlexPay::checkPaiement($orderNumber);


            if($result['code']!=0){
                $push = new FirebasePushNotification();
                $push->sendPushNotification($order?->user->expo_push_token,"Erreur paiement", $result['message']);
            }
            else{

                $status=$result['transaction']['status'];

                $status_paiement = StatusPayement::query()->where('code', $status)->first();

                Payement::query()->updateOrCreate([
                    'commande_id' => $order?->id,
                    'phone' => preg_replace('/[\s+]/', '', $phone),
                    'channel' => "MPESA",
                ], [
                    'code' => $result['code'],
                    'commande_id' => $order->id,
                    'phone' => preg_replace('/[\s+]/', '', $phone),
                    'channel' => $channel,
                    'status_payement_id' => $status_paiement?->id,
                    'amount' => $amount,
                    'amount_customer' => $amountCustomer,
                    "provider_reference"=>$provider_reference
                ]);

                $orderResource=new CommandeResource($order);
                if($status_paiement->is_paied)
                {
                    $order->status_id = 2;
                    $order->reference_paiement;
                    //envoyer la commande au restaurateur
                    $order->paied_at = now()->format("Y-m-d H:i:s");
                    $order->save();

                    $user=CurrentHelpers::getUserByOrder($order);

                    if($user)
                    {
                        $body=[
                            'action'=>'paiement-check',
                            'status'=>$status_paiement,
                        ];
                        $push = new FirebasePushNotification();
                        $push->sendPushNotification($user->expo_push_token, "Nouvelle commande", json_encode($body));
                    }
                }

                $body=[
                    'action'=>'paiement-check',
                    'status'=>$status_paiement,
                ];
                $push = new FirebasePushNotification();
                $push->sendPushNotification($order?->user->expo_push_token, $result['message'], json_encode($body));
            }

            //event(new PayementEvent($orderNumber,$reference));

            return ApiResponse::SUCCESS_DATA('');
        }
        catch(Exception $e){
            dd($e);
            return ApiResponse::SERVER_ERROR($e);
        }
    }


}
