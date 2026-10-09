<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // La valeur deja appliquee, reprise telle quelle : la migration
        // deplace le reglage, elle ne change aucun comportement.
        $this->migrator->add('expiration.minutes', (int) config('expiration.minutes', 15));
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('expiration.minutes');
    }
};
