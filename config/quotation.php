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

    'authoritative' => (bool) env('QUOTATION_AUTHORITATIVE', false),

];
