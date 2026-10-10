<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Origine publique du serveur d'autorisation
    |--------------------------------------------------------------------------
    |
    | L'emetteur annonce dans le document de decouverte (RFC 8414), et la base
    | des quatre adresses qu'il publie.
    |
    | Elle ne derive PAS d'APP_URL : dans ce projet, APP_URL n'est pas
    | maintenue comme l'origine publique (en local, elle pointe vers un tunnel
    | ngrok jetable, et .env.example la livre sur 127.0.0.1:8000). Un assistant
    | qui lit ce document y prend l'adresse ou demander une autorisation et ou
    | echanger son code : si elle differe du domaine de production, le parcours
    | en un clic ne demarre tout simplement pas, et au pire on publie un
    | emetteur en http alors que le connecteur MCP annonce https.
    |
    | Par defaut, elle retombe donc sur le domaine de production, exactement
    | comme l'adresse de rappel FlexPay. OAUTH_ORIGINE force une autre valeur
    | lorsque l'API est reellement joignable sous un autre domaine.
    |
    */

    'origine' => env('OAUTH_ORIGINE', 'https://app.thaliaeats.com'),

];
