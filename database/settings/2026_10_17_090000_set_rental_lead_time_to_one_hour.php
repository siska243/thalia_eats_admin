<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        /*
         * « Commande 1 h avant » (T123).
         *
         * Le delai etait a trente minutes depuis l'ouverture du service. Le
         * proprietaire le porte a une heure : c'est le temps reel pour
         * joindre un chauffeur et le faire partir.
         *
         * Pose ici et pas a la main dans l'administration, parce qu'une
         * valeur saisie en local ne part jamais en production : le
         * deploiement joue les migrations et rien d'autre — il ne lance
         * jamais `db:seed`, comme T114 l'a montre.
         *
         * Une migration ne joue qu'UNE fois : si le proprietaire desserre le
         * delai plus tard depuis l'administration, ce fichier ne reviendra
         * pas ecraser son choix.
         */
        $this->migrator->update(
            'rental.lead_time_minutes',
            fn () => 60,
        );
    }

    public function down(): void
    {
        $this->migrator->update(
            'rental.lead_time_minutes',
            fn () => 30,
        );
    }
};
