<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Autorité sur le prix
    |--------------------------------------------------------------------------
    |
    | Le serveur fait foi : le montant facturé est celui que calcule le moteur
    | de quotation, pas celui qu'annonce le téléphone. Décision du propriétaire,
    | prise au vu de la simulation sur les commandes réelles (`quotation:simuler`) :
    | sur les dix dernières commandes exploitables, le serveur facturait au
    | centime près ce qui avait été facturé.
    |
    | QUOTATION_AUTHORITATIVE=false dans le `.env` rend la main au client sans
    | redéploiement : c'est le bouton d'arrêt d'urgence, il doit continuer de
    | fonctionner.
    |
    | La ventilation suit le total. `CommandeController::valide()` prend de la
    | quotation, au même moment, `price_delivery`, `price_service` et la devise
    | des lignes : un total qui vient du serveur avec des frais qui viennent du
    | client produirait une commande dont les lignes ne font pas la somme, et
    | personne, en rapprochant les chiffres dans Filament, ne saurait lequel
    | fait foi.
    |
    | Ce que ce drapeau ne décide PAS : les cas où la cotation n'est pas
    | disponible. Voir `CommandeController::refuserFauteDeCotation()`.
    |
    */

    // filter_var(..., FILTER_VALIDATE_BOOL) et non un simple (bool) cast :
    // (bool) "no", (bool) "off" et (bool) "disabled" valent tous true en PHP
    // (seule une chaine vide ou "0" vaut false) — un faux negatif ici
    // rendrait le serveur autorite sur ce qui est facture sans que personne
    // ne l'ait voulu.
    'authoritative' => filter_var(env('QUOTATION_AUTHORITATIVE', true), FILTER_VALIDATE_BOOL),

];
