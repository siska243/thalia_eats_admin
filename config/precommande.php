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
    | Délai avant de pouvoir relancer un paiement
    |--------------------------------------------------------------------------
    |
    | Une pré-commande ne se ferme qu'à la réception du webhook. Entre l'appel
    | à la passerelle et cette confirmation, rien n'empêchait un second POST de
    | rappeler FlexPay : le téléphone du client sonnait deux fois pour la même
    | commande, et s'il confirmait la première sollicitation, la référence
    | enregistrée n'était plus celle qui avait été payée.
    |
    | Pendant ce délai, une initiation déjà partie interdit la suivante et le
    | client est invité à regarder son téléphone. Au-delà, on suppose la
    | première sollicitation perdue et on laisse réessayer.
    |
    */

    'delai_relance_paiement_minutes' => (int) env('PRECOMMANDE_DELAI_RELANCE_MINUTES', 5),

];
