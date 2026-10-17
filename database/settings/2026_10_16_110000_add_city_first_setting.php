<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        /*
         * La ville prime sur la distance.
         *
         * Le proprietaire, le 16 octobre : « on tient compte soit de la
         * ville, et la distance c'est un repli ». Deux adresses de la meme
         * ville se livrent, quelle que soit la distance — Kinshasa fait plus
         * de quarante kilometres de large, et un rayon de trente y refusait
         * des livraisons parfaitement normales.
         *
         * Le rayon reprend la main dans les deux cas ou la ville ne tranche
         * pas : villes differentes, ou ville inconnue d'un cote.
         *
         * Eteinte par defaut : allumee d'office, elle elargirait ce qui est
         * accepte en production sans que personne ne l'ait demande. C'est a
         * l'administration de l'activer.
         */
        $this->migrator->add('food.city_first', false);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('food.city_first');
    }
};
