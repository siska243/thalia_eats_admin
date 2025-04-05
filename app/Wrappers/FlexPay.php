<?php

namespace App\Wrappers;

use App\Models\ConfigurationPayement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlexPay
{

    const url = "https://backend.flexpay.cd/api/rest/v1";

    const urlCart = "https://cardpayment.flexpay.cd/v1.1/pay";

    public static function sendData(array $data, string $method = 'mobile')
    {

        $formData = [
            'merchant' => self::getConfig()?->token_key,
            'type' => "1",
            'amount' => $data['amount'],
            'phone' => $data['phone'],
            'currency' => $data['currency'],
            'reference' => $data['reference'],
            'callbackUrl' => $data['callback_url'],
            'callback_url' => $data['callback_url'],
            'approve_url' => $data['approve_url'],
            'cancel_url' => $data['cancel_url'],
            "decline_url" => $data['decline_url'],
            'language' => "fr",
            'description' => !empty($data['description']) ? $data['description'] : "",
            'name'=>$data['name'],
            'email'=>$data['email'],
        ];

        if($method != 'mobile'){
            $token=self::getConfig()?->token;
            $formData["authorization"] = "Bearer {$token}";
        }

        $response = Http::withToken(self::getConfig()?->token)
            ->timeout(120)
            ->post($method == "mobile" ? self::url . "/paymentService" : self::urlCart, $formData);

        if ($response->successful()) {
            return $response->json();
        }
        return $response->json();

    }

    public static function checkPaiement(string $orderNumber)
    {
        try {
            $url = self::url . "/check/" . $orderNumber;
            $response = Http::withToken(self::getConfig()?->token)
                ->timeout(120)
                ->get($url);

            if ($response->successful()) {

                return $response->json();
            }
            return $response->json();
        } catch (\Exception $exception) {
            return $exception;
        }
    }

    public static function getConfig()
    {
        return ConfigurationPayement::query()->where('active', true)->first();
    }
}
