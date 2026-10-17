<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        /*
         * L'interrupteur general du suivi de stock.
         *
         * Le suivi se decide PLAT PAR PLAT — « le stock est pris en charge que
         * si on l'active depuis l'admin dans chaque produit » — et c'est cette
         * decision-la qui commande. Celui-ci ne sert qu'a tout eteindre d'un
         * coup si la fonctionnalite se comporte mal en production, sans avoir
         * a repasser sur chaque plat.
         *
         * ALLUME par defaut, contrairement aux autres reglages de ce groupe :
         * eteint, il annulerait la decision que l'administration vient de
         * prendre sur un plat. Et l'allumer n'elargit rien, puisque aucun plat
         * n'est suivi tant qu'on ne l'a pas dit.
         */
        $this->migrator->add('food.stock_actif', true);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('food.stock_actif');
    }
};
