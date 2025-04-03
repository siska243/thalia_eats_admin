<?php

namespace App\Wrappers;

use App\Models\ConfigurationPayement;
use Illuminate\Support\Facades\Http;

class FlexPay
{

    const url="https://backend.flexpay.cd/api/rest/v1";
    public static function sendData(array $data){

        $formData=[
            'merchant'=>self::getConfig()?->token_key,
            'type'=>"1",
            'amount'       => $data['amount'],
            'phone'=>$data['phone'],
            'currency'     => $data['currency'],
            'reference'     => $data['reference'],
            'callbackUrl'=> $data['callback_url'],
        ];
        $response = Http::withToken(self::getConfig()?->token)
            ->timeout(120)
            ->post(self::url."/paymentService",$formData);

        if ($response->successful()) {
            return $response->json();
        }
        return $response->json();

    }

    public static function checkPaiement(string $orderNumber)
    {
        try{
            $url=self::url."/check/".$orderNumber;
            $response = Http::withToken(self::getConfig()?->token)
                ->timeout(120)
                ->get($url);

            if ($response->successful()) {

                return $response->json();
            }
            return $response->json();
        }
        catch (\Exception $exception){
            return $exception;
        }
    }

    public static function getConfig()
    {
        return ConfigurationPayement::query()->where('active',true)->first();
    }
}
