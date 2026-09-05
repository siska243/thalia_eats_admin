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
    | L'adresse ne derive PAS d'APP_URL : dans ce projet, APP_URL n'est pas
    | maintenue comme l'origine publique (en local, elle pointe vers un
    | tunnel ngrok jetable). La deduire d'APP_URL enverrait FlexPay confirmer
    | un paiement sur une adresse ou personne n'ecoute des que APP_URL differe
    | du domaine de production : le client est debite, la commande reste au
    | statut 5, et rien ne le detecte.
    |
    | Par defaut, l'adresse retombe donc sur le domaine de production
    | (app.thaliaeats.com) : c'est le repli le plus sur pour une confirmation
    | de paiement, deliberement, meme quand on teste depuis un environnement
    | different. FLEXPAY_CALLBACK_URL force une autre adresse lorsque l'API
    | est reellement joignable sous un domaine different.
    |
    */

    'callback_url' => env(
        'FLEXPAY_CALLBACK_URL',
        'https://app.thaliaeats.com/api/webhook-paiement-flexpay'
    ),

];
