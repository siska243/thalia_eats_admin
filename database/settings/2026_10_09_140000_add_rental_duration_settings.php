<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Les valeurs que l'application mobile appliquait deja en dur : la
        // migration ne change aucun comportement, elle deplace la regle du
        // telephone vers le serveur.
        $this->migrator->add('rental.hours_min', 1);
        $this->migrator->add('rental.hours_max', 23);
        $this->migrator->add('rental.days_min', 1);
        $this->migrator->add('rental.days_max', 30);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('rental.hours_min');
        $this->migrator->deleteIfExists('rental.hours_max');
        $this->migrator->deleteIfExists('rental.days_min');
        $this->migrator->deleteIfExists('rental.days_max');
    }
};
