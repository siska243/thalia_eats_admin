<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        /*
         * Le montant minimum d'un panier, en dessous duquel la commande est
         * refusee.
         *
         * ZERO PAR DEFAUT, c'est-a-dire aucune limite. Ce reglage arrive sur
         * une application en production : poser une valeur ici refuserait du
         * jour au lendemain des paniers que la plateforme accepte
         * aujourd'hui, sans que personne ne l'ait decide. C'est a
         * l'administration de l'allumer, depuis Parametres > Livraison des
         * repas.
         *
         * Exprime dans la devise de la commande, qui est celle des plats
         * commandes. La plateforme n'a qu'une devise active a la fois : un
         * seul nombre suffit, et en poser un par devise inventerait une
         * complexite que personne n'a demandee.
         */
        $this->migrator->add('food.montant_minimum_commande', 0.0);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('food.montant_minimum_commande');
    }
};
