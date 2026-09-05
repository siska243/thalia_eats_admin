<?php

return [

    /*
    |--------------------------------------------------------------------------
    | URL de rappel du webhook
    |--------------------------------------------------------------------------
    |
    | Adresse que FlexPay appelle, de serveur a serveur, pour confirmer un
    | paiement. Elle etait ecrite en dur a deux endroits du CommandeController,
    | avec deux valeurs differentes : valide() imposait app.thaliaeats.com en
    | ignorant la valeur envoyee par le client, tandis que paiement() reprenait
    | celle du client, qui pointait vers un autre domaine.
    |
    | La valeur vient desormais d'un seul endroit, et le client ne decide plus
    | ou son propre paiement sera confirme.
    |
    | Par defaut, l'adresse est deduite d'APP_URL : le webhook revient donc
    | toujours sur l'instance qui a cree la commande. FLEXPAY_CALLBACK_URL
    | permet de forcer une autre adresse lorsque l'API est joignable sous un
    | domaine distinct de celui configure dans APP_URL.
    |
    */

    'callback_url' => env(
        'FLEXPAY_CALLBACK_URL',
        rtrim(env('APP_URL', 'http://127.0.0.1:8000'), '/').'/api/webhook-paiement-flexpay'
    ),

];
