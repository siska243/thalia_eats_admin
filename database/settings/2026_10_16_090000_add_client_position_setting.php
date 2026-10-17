<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        /*
         * Eteinte par defaut.
         *
         * Allumee, la position physique du client compte en plus de son
         * adresse de livraison : au Maroc, les restaurants de Kinshasa ne sont
         * plus commandables, meme avec une adresse a Gombe.
         *
         * C'est une decision commerciale, pas technique : selon qu'on veut
         * servir quelqu'un sur place ou laisser commander pour sa famille a
         * distance, la reponse change. Elle s'active donc sans livraison.
         *
         * Faux au depart parce que c'est le comportement d'aujourd'hui :
         * l'allumer d'office aurait change la regle sous les pieds des clients
         * deja installes.
         */
        $this->migrator->add('food.use_client_position', false);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('food.use_client_position');
    }
};
