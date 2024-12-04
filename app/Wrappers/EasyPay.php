<?php

namespace App\Wrappers;

use Illuminate\Support\Facades\Http;

class EasyPay
{

    private $url;

    public function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'Accept-encoding' => 'gzip, deflate',
            'Content-Type' => 'application/json; charset=utf-8'
        ];
    }

    public function body(string $order_ref, $amount, string $currency, string $description, string $client_name, string $client_email, string $success_url, string $error_url, string $cancel_url)
    {
        return [
            "order_ref" => $order_ref,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'success_url' => $success_url,
            'error_url' => $error_url,
            'cancel_url' => $cancel_url,
            'language' => 'FR',
            'channels' => [
                ["channel" => "CREDIT CARD"], ["channel" => "MOBILE MONEY"]
            ],
            "customer_name" => $client_name,
            "customer_email" => $client_email
        ];
    }
    public function setUrl(string $cid, string $token): self
    {
        $this->url = "https://www.e-com-easypay.com/" . EasyPayMode::SANDBOX->value . "/payment/initialization?cid=" . $cid . '&token=' . $token;
        return $this;
    }
    public function getUrl()
    {
        return $this->url;
    }
    public static function SEND_DATA(
        string $order_ref,
        $amount,
        string $currency,
        string $description,
        string $client_name,
        string $client_email,
        string $success_url,
        string $error_url,
        string $cancel_url
    ) {
        $parent = new self();
        $parent->setUrl(EasyPayMode::CID->value, EasyPayMode::TOKEN->value);
        return Http::withHeaders($parent->headers())
            ->post($parent->getUrl(), $parent->body(
                $order_ref,
                $amount,
                $currency,
                $description,
                $client_name,
                $client_email,
                $success_url,
                $error_url,
                $cancel_url
            ));
    }

    public static function CREATE_REFERENCE()
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post('https://www.e-com-easypay.com/' . (EasyPayMode::SANDBOX)->value . '/payment/initialization?cid=' . EasyPayMode::CID->value . '&&token=' . EasyPayMode::TOKEN->value, [
            'order_ref' => '$order_ref',
            'amount' => 1,
            'currency' => 'USD',
            'description' => '$description',
            'success_url' => '$success_url',
            'error_url' => '$error_url',
            'cancel_url' => '$cancel_url',
            'language' => 'FR',
            'channels' =>  ["channel" => "CREDIT CARD"], ["channel" => "MOBILE MONEY"],
            'customer_name' => "Emmanuel",
            'customer_email' => '',
        ])->throw()->json();

        $EASYPAY_INIT_TRANSACTION_REFERENCE_RESPONSE = $response['reference'];

        //return $response;
        if ($response['code'] == 1) {
            return 'https://www.e-com-easypay.com/sandbox/payment/initialization?reference=' . $EASYPAY_INIT_TRANSACTION_REFERENCE_RESPONSE;
        }
    }
    public static function EASEY_APY_REDIRECT(string $reference):string
    {
        $mode=EasyPayMode::SANDBOX->value;
        return "https://www.e-com-easypay.com/{$mode}/payment/initialization?reference={$reference}";
    }

    /**
     * @param string $reference
     * @return bool
     */
    public static function EASY_PAY_VERIF_TRANSACTION(string $reference):bool{

        $mode=EasyPayMode::SANDBOX->value;
        $parent = new self();
        $parent->setUrl(EasyPayMode::CID->value, EasyPayMode::TOKEN->value);
        $response=Http::withHeaders($parent->headers())
            ->post( "https://www.e-com-easypay.com/{$mode}/payment/{$reference}/checking-payment");

            $response->status() == 200;

            if(   $response->status() == 200){

                return true;
            }

            return false;

    }
}
