<?php

namespace App\Wrappers;

use Illuminate\Support\Facades\Http;

class Notification
{
    static $URL = "https://exp.host/--/api/v2/push/send";
    static $DEFAULT_EXPO_PUSH_TOKEN="ExponentPushToken[HxsIy6FMQxNRGHn1U_O-lp]";

    public function body($title, $content):array
    {

        return [
            'to' => self::$DEFAULT_EXPO_PUSH_TOKEN,
            'sound' => 'default',
            'title' => $title,
            'body' => $content,
            'data' => [
                'someData' => 'goes here'
            ]
        ];
    }

    public function headers():array
    {
        return [
            'Accept' => 'application/json',
            'Accept-encoding' => 'gzip, deflate',
            'Content-Type' => 'application/json'
        ];
    }
    public static function SEND_NOTIFICATION($title, $content)
    {
        $response=Http::withHeaders(
            (new self())->headers()
        )->withBody(
            json_encode((new self)->body($title,$content))
        )->post(self::$URL);

        return $response;
    }
}
