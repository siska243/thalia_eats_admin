<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        /*
         * Trente kilometres : la borne haute des deux valeurs citees par le
         * proprietaire (« 20 km ou 30 km maximum »). On ouvre au plus large,
         * et chaque ville resserre si elle le veut — l'inverse aurait ferme
         * des livraisons qui se font aujourd'hui.
         */
        $this->migrator->add('food.max_delivery_radius_km', 30.0);

        $this->migrator->add(
            'food.out_of_radius_message',
            ':restaurant est à :distance km de votre adresse de livraison. '
            .'Nous ne livrons que dans un rayon de :rayon km.',
        );
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('food.max_delivery_radius_km');
        $this->migrator->deleteIfExists('food.out_of_radius_message');
    }
};
