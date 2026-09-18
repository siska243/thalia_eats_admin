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

    /*
    |--------------------------------------------------------------------------
    | Plafond de pré-commandes actives par client
    |--------------------------------------------------------------------------
    |
    | Compte les pré-commandes EN ATTENTE et non expirées, jamais le total
    | historique : un client qui en a payé cent doit pouvoir en créer une
    | cent-unième. Le plafond se libère donc tout seul, par paiement ou par
    | expiration, sans que personne n'ait à intervenir.
    |
    | Il existe parce qu'un assistant crée sans effort : une boucle maladroite,
    | et le client reçoit quarante liens de paiement. Chaque pré-commande fige
    | aussi un prix, donc chacune est un engagement commercial de Thalia.
    |
    */

    'plafond_actives' => (int) env('PRECOMMANDE_PLAFOND_ACTIVES', 8),

];
