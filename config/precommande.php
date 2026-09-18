<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Durée de validité
    |--------------------------------------------------------------------------
    |
    | Une pré-commande fige son prix pour cette durée. C'est donc la mesure de
    | l'exposition commerciale : pendant ce laps, Thalia honore le devis même
    | si un tarif produit ou une tranche de livraison a changé entre-temps.
    |
    */

    'validite_heures' => (int) env('PRECOMMANDE_VALIDITE_HEURES', 12),

    /*
    |--------------------------------------------------------------------------
    | Visibilité après expiration
    |--------------------------------------------------------------------------
    |
    | Une pré-commande expirée n'est JAMAIS supprimée. Elle sort simplement des
    | listes au bout de ce délai, pour qu'un assistant puisse encore dire
    | « ta commande d'hier a expiré, je te la refais ? ».
    |
    */

    'visibilite_jours' => (int) env('PRECOMMANDE_VISIBILITE_JOURS', 30),

];
