<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // La regle commerciale passe de 10 a 20 %. En migration a part, et pas
        // en retouchant la migration de creation : celle-ci ne se rejoue
        // jamais sur une base deja installee, la valeur serait restee a 10 en
        // production.
        $this->migrator->update('rental.deposit_percentage', fn () => 20.0);
    }

    public function down(): void
    {
        $this->migrator->update('rental.deposit_percentage', fn () => 10.0);
    }
};
