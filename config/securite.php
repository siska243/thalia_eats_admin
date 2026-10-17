<?php

/*
 * Les gardes de securite reglables.
 *
 * « Tu ne fais rien en dur » — le proprietaire, le 17 octobre. Les bornes
 * d'un limiteur sont une decision d'exploitation, pas une constante : elles
 * se resserrent le jour d'une attaque et se desserrent si elles genent, sans
 * relivrer le code.
 */
return [

    /*
     * Le filet general sur les actions QUI ECHOUENT.
     *
     * Toute reponse en 4xx compte, sur n'importe quelle route de l'API. Un
     * attaquant qui cherche une faille enchaine les refus ; un client normal
     * n'en produit presque jamais.
     */
    'limite_echecs' => [

        /*
         * ETEINT EN TEST, allume partout ailleurs.
         *
         * La suite de tests parle depuis une seule adresse, 127.0.0.1, et
         * plusieurs classes enchainent legitimement des dizaines de refus
         * attendus — un ecran d'administration verifie vingt interdictions
         * d'affilee. Le compteur les additionne et bloque la suite, alors
         * que le comportement est juste.
         *
         * `LimiteDesEchecsTest` le rallume explicitement : la protection
         * reste donc PROUVEE, sans empoisonner le reste.
         */
        'actif' => (bool) env('LIMITE_ECHECS_ACTIF', true),

        /*
         * DEUX BORNES, ET ELLES NE SE VALENT PAS.
         *
         * Par COMPTE, c'est serre : personne ne produit vingt refus par
         * minute en se servant de l'application. C'est le signal fiable.
         *
         * Par ADRESSE, c'est large. Derriere un operateur mobile congolais,
         * des milliers de clients partagent une poignee d'adresses : serrer
         * la vis au meme niveau que sur un compte ferait que le premier
         * maladroit enferme tous les autres dehors. L'adresse ne sert donc
         * qu'a arreter un flot manifeste, et a couvrir le trafic anonyme,
         * qui n'a pas de compte.
         */
        'compte_par_minute' => (int) env('LIMITE_ECHECS_COMPTE_PAR_MINUTE', 20),

        'compte_par_heure' => (int) env('LIMITE_ECHECS_COMPTE_PAR_HEURE', 100),

        /*
         * TRENTE, et non soixante : le limiteur de debit general en autorise
         * deja soixante par minute et par adresse. Une borne posee au meme
         * niveau ne mordrait jamais — le limiteur general aurait toujours
         * parle avant, et ce filet ne servirait a rien pour le trafic
         * anonyme.
         *
         * A trente refus la minute, on n'est plus devant quelqu'un qui se
         * trompe, meme partage entre plusieurs clients d'un meme operateur.
         */
        'adresse_par_minute' => (int) env('LIMITE_ECHECS_ADRESSE_PAR_MINUTE', 30),

        'adresse_par_heure' => (int) env('LIMITE_ECHECS_ADRESSE_PAR_HEURE', 200),
    ],

    /*
     * La confirmation de l'adresse electronique.
     *
     * Le mecanisme existait deja — l'inscription envoie un code,
     * `/activation-account` le verifie — mais rien n'obligeait a s'en
     * servir : on commandait sans avoir jamais prouve qu'on possede
     * l'adresse. Deux inscriptions au nom manifestement fabrique l'ont
     * montre le 17 octobre.
     */
    /*
     * Cloudflare Turnstile sur le formulaire d'inscription du site.
     *
     * VIDE = ETEINT, et l'inscription se comporte comme avant. C'est voulu :
     * un garde qui refuserait tout parce qu'une variable manque fermerait les
     * inscriptions sans prevenir, le jour du deploiement.
     */
    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET_KEY'),
        'timeout' => (int) env('TURNSTILE_TIMEOUT', 5),
    ],

    'confirmation_email' => [

        /*
         * LA DATE PIVOT. Seuls les comptes crees a partir de ce moment sont
         * tenus de confirmer avant de commander.
         *
         * Pourquoi une date et pas un simple oui/non : dix-neuf des
         * vingt-trois comptes existants n'ont jamais ete confirmes. Exiger
         * la confirmation pour tout le monde fermerait la porte a de vrais
         * clients du jour au lendemain, sans qu'ils comprennent pourquoi.
         *
         * Et on ne marque pas les anciens « verifies » pour s'en
         * debarrasser : ce serait ecrire une chose fausse en base, et on ne
         * saurait plus jamais qui a reellement prouve son adresse.
         *
         * VIDE, LA REGLE NE S'APPLIQUE A PERSONNE : c'est l'etat d'avant, et
         * le retour arriere tient en une variable d'environnement.
         */
        'exigee_depuis' => env('CONFIRMATION_EMAIL_EXIGEE_DEPUIS'),
    ],
];
