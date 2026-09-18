<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Origine publique du site
    |--------------------------------------------------------------------------
    |
    | L'adresse du site Next.js (thaliaeats.com), distincte de celle de l'API
    | (app.thaliaeats.com). C'est elle qui porte desormais la page de paiement
    | d'une pre-commande : le lien envoye au client mene au site, plus a une
    | page Blade servie par le backend.
    |
    | L'adresse ne derive PAS d'APP_URL, pour la meme raison que
    | FLEXPAY_CALLBACK_URL : dans ce projet APP_URL n'est pas maintenue comme
    | l'origine publique — .env.example la livre sur http://127.0.0.1:8000 et
    | en local elle pointe vers un tunnel ngrok jetable. En deduire le lien de
    | paiement enverrait le client sur une machine ou personne n'ecoute, avec
    | un repas commande et aucun moyen de payer.
    |
    | Par defaut, l'adresse retombe donc sur le domaine de production : c'est
    | le repli le plus sur pour un lien qui part chez un client. SITE_URL force
    | une autre origine (site local sur le port 3000, preview de deploiement).
    |
    */

    'url' => rtrim(env('SITE_URL', 'https://thaliaeats.com'), '/'),

];
