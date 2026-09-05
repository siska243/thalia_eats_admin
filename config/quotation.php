<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Autorité sur le prix
    |--------------------------------------------------------------------------
    |
    | À false, le montant facturé reste celui envoyé par le client, et le moteur
    | de quotation se contente de journaliser les écarts sur le canal
    | « quotation ». À true, le serveur fait autorité.
    |
    | Ne passer à true qu'après une période d'observation sans « ecart_quotation »
    | dans les logs, couvrant plusieurs towns et plusieurs tranches.
    | Réversible sans redéploiement de code.
    |
    | ATTENTION — passer ce drapeau à true n'est PAS suffisant à lui seul.
    | Aujourd'hui, seul `global_price` viendrait du serveur si authoritative
    | valait true ; `CommandeController::valide()` continue par ailleurs à
    | prendre `price_delivery`, `price_service` et `commande_products.currency_id`
    | du `$pricing` envoyé par le client. Un client qui enverrait
    | `frais_livraison: 0, service_price: 0` avec un total sous-évalué
    | produirait alors une Commande dont la ventilation stockée ne
    | correspondrait plus à son propre `global_price` — sans qu'un admin qui
    | rapproche les chiffres dans Filament puisse savoir lequel fait foi.
    |
    | Le jour où l'on flippe ce drapeau, il faut aussi faire venir de la
    | quotation, au même moment et au même endroit (CommandeController::valide()) :
    | - price_delivery
    | - price_service
    | - commande_products.currency_id
    |
    */

    // filter_var(..., FILTER_VALIDATE_BOOL) et non un simple (bool) cast :
    // (bool) "no", (bool) "off" et (bool) "disabled" valent tous true en PHP
    // (seule une chaine vide ou "0" vaut false) — un faux negatif ici
    // rendrait le serveur autorite sur ce qui est facture sans que personne
    // ne l'ait voulu.
    'authoritative' => filter_var(env('QUOTATION_AUTHORITATIVE', false), FILTER_VALIDATE_BOOL),

];
