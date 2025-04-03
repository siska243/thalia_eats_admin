<?php

namespace App\Wrappers;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
class FirebasePushNotification
{
    public function sendPushNotification($deviceToken, $title, $body,string |null $image=null)
    {
        $messaging = app('firebase.messaging');

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body,$image))
            ->toToken($deviceToken);

        $messaging->send($message);
    }
}
