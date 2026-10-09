<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Délai avant expiration d'une réservation ou d'une commande non réglée
    |--------------------------------------------------------------------------
    |
    | Un client ne peut avoir qu'une seule course et qu'une seule commande en
    | cours à la fois. Tant qu'elle n'est ni payée ni annulée, il est bloqué :
    | ce délai est donc le temps maximum pendant lequel il peut rester bloqué
    | par une ligne qu'il a abandonnée.
    |
    | La valeur vit ICI, une seule fois, pour la location et pour la
    | restauration. Deux constantes recopiées, c'est exactement le motif qui a
    | déjà produit deux bugs de production sur ce dépôt : la copie oubliée
    | continue d'appliquer l'ancienne règle, et les deux moitiés du produit ne
    | répondent plus la même chose.
    |
    | Surchargeable par EXPIRATION_MINUTES, sans livraison : le bon délai est
    | commercial, il s'ajustera en regardant les abandons réels.
    |
    */

    'minutes' => (int) env('EXPIRATION_MINUTES', 15),

];
