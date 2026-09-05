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
    */

    // filter_var(..., FILTER_VALIDATE_BOOL) et non un simple (bool) cast :
    // (bool) "no", (bool) "off" et (bool) "disabled" valent tous true en PHP
    // (seule une chaine vide ou "0" vaut false) — un faux negatif ici
    // rendrait le serveur autorite sur ce qui est facture sans que personne
    // ne l'ait voulu.
    'authoritative' => filter_var(env('QUOTATION_AUTHORITATIVE', false), FILTER_VALIDATE_BOOL),

];
