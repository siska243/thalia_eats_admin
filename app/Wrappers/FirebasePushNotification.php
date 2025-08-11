<?php

namespace App\Wrappers;

use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebasePushNotification
{

    static $URL = "https://exp.host/--/api/v2/push/send";
    static $DEFAULT_EXPO_PUSH_TOKEN = "ExponentPushToken[HxsIy6FMQxNRGHn1U_O-lp]";

    public function body($token, $title, $content): array
    {

        return [
            'to' => $token,
            'sound' => 'default',
            'title' => $title,
            'body' => $content,
            'data' => [
                'someData' => 'goes here'
            ]
        ];
    }

    public function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'Accept-encoding' => 'gzip, deflate',
            'Content-Type' => 'application/json'
        ];
    }

    public function sendPushNotification($deviceToken, $title, $body, string|null $image = null)
    {
        $messaging = app('firebase.messaging');

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body, $image))
            ->withDefaultSounds()
            ->toToken($deviceToken);

        try {
            $messaging->send($message);
        } catch (\Exception $exception) {

        }

    }

    public function sendPushNotificationMultiUser(array $deviceTokens, $title, $body, string|null $image = null)
    {
        $messaging = app('firebase.messaging');

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body, $image))
            ->withDefaultSounds();

        $results = $messaging->validateRegistrationTokens($deviceTokens);

        $validTokens = $results["valid"];
        if (!empty($validTokens)) {
            $messaging->sendMulticast($message, $validTokens);
        }


    }

    public static function sendNotification(string $token, $title, $content): void
    {
        $data = (new self)->body($token, $title, $content);
        Http::withHeaders(
            (new self())->headers()
        )->withBody(
            json_encode($data)
        )->post(self::$URL, $data);

    }

    public static function sendMultiNotification(array $tokens, $title, $content): void
    {
        try {

            collect($tokens)->each(function ($token) use ($title, $content) {
                self::sendNotification($token, $title, $content);
            });

        } catch (\Exception $e) {

        }

    }

}
