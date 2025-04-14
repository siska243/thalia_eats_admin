<?php

namespace App\Wrappers;

use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebasePushNotification
{
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

        $validTokens = $results->valid();
        if (!empty($validTokens)) {
            $messaging->sendMulticast($message, $validTokens);
        }


    }

}
