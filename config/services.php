<?php

use App\Enums\CallBackEnum;

return [

    /*
     * La cle secrete Stripe etait ecrite en clair dans App\Enums\CallBackEnum,
     * donc committee et lisible par quiconque a acces au depot.
     *
     * La valeur par defaut reste celle d'aujourd'hui, pour que rien ne change
     * tant que le proprietaire n'a pas renseigne STRIPE_SECRET : une cle
     * committee est compromise, la sortir du code ne suffit pas, il faut la
     * revoquer et la regenerer, et cette decision ne se prend pas ici.
     */
    'stripe' => [
        'secret' => env('STRIPE_SECRET', CallBackEnum::SK_KEY->value),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT'),
    ],
    'redirect_uris' => [
        'mobile_register' => env('REDIRECT_URI_MOBILE_REGISTER'),
        'mobile_login' => env('REDIRECT_URI_MOBILE_LOGIN'),
        'prod' => env('REDIRECT_URI_PROD'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

];
