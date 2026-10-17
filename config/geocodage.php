<?php

/*
 * Le geocodage : retrouver une position a partir d'une adresse.
 *
 * « Tu ne fais rien en dur » — le proprietaire, le 17 octobre. Tout ce qui
 * peut changer sans qu'on relivre le code vit donc ici : le service
 * interroge, le pays, la langue, le contact, les delais et les abreviations.
 *
 * Les valeurs par defaut sont celles qui marchent aujourd'hui : sans aucune
 * variable d'environnement, le geocodage fonctionne tel quel.
 */
return [

    /*
     * OpenStreetMap et non Google : aucune cle a prevoir, aucun secret a
     * commiter. C'est deja le choix fait pour la carte de suivi des courses.
     *
     * L'adresse est reglable : une instance Nominatim auto-hebergee se
     * substitue a celle d'OpenStreetMap sans toucher au code, le jour ou le
     * volume l'exigera.
     */
    'url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search'),

    /*
     * Le pays dans lequel chercher, en code ISO. Vide : le monde entier.
     *
     * Par defaut la RDC, pour qu'une adresse de Kinshasa ne ramene pas une
     * rue homonyme a Paris. A changer le jour ou le service ouvre ailleurs —
     * plusieurs pays se separent par une virgule, comme Nominatim l'attend.
     */
    'pays' => env('GEOCODAGE_PAYS', 'cd'),

    'langue' => env('GEOCODAGE_LANGUE', 'fr'),

    /** Au-dela, on considere que le service ne repond pas. */
    'delai' => (int) env('GEOCODAGE_DELAI', 8),

    /*
     * Nominatim REFUSE les requetes anonymes et demande un moyen de nous
     * joindre en cas d'abus. Le service est tenu par des benevoles : sans
     * contact valable, on se fait bannir sans preavis.
     *
     * CE DOIT ETRE UNE VRAIE ADRESSE. Le 17 octobre, la valeur par defaut
     * tombait sur `MAIL_FROM_ADDRESS`, qui vaut `hello@example.com` dans ce
     * depot : Nominatim a repondu 403 « Access denied ». La valeur par
     * defaut est donc l'adresse de contact du service, celle du pied de page
     * du site, et non une variable qui peut contenir n'importe quoi.
     */
    'contact' => env('GEOCODAGE_CONTACT', 'thaliaeat.original@gmail.com'),

    /*
     * Les abreviations qu'OpenStreetMap ecrit en toutes lettres.
     *
     * Ce sont des regles de langue, pas du code : les completer ne doit pas
     * demander une livraison. La cle est cherchee comme un mot entier, point
     * final facultatif.
     */
    'abreviations' => [
        'av' => 'Avenue',
        'ave' => 'Avenue',
        'bd' => 'Boulevard',
        'bld' => 'Boulevard',
        'blvd' => 'Boulevard',
        'rte' => 'Route',
        'pl' => 'Place',
        'sq' => 'Square',
        'imm' => 'Immeuble',
        'q' => 'Quartier',
    ],
];
