<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notification temps reel
    |--------------------------------------------------------------------------
    |
    | Adresse appelee par le webhook de paiement pour pousser un evenement
    | vers le flux SSE. Elle vient de l'environnement et jamais de la requete :
    | le webhook est public, et le serveur emettrait sinon des requetes vers
    | une destination choisie par l'appelant.
    |
    | Laisser vide desactive la notification.
    |
    */

    'webhook_url' => env('SSE_WEBHOOK_URL'),

];
