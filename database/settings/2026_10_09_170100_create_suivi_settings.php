<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Cinq minutes : la cadence demandee. Quinze minutes de silence
        // suffisent a douter d'une position. Quatre-vingt-dix jours de
        // conservation, avec export avant purge.
        $this->migrator->add('suivi.interval_minutes', 5);
        $this->migrator->add('suivi.stale_minutes', 15);
        $this->migrator->add('suivi.retention_days', 90);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('suivi.interval_minutes');
        $this->migrator->deleteIfExists('suivi.stale_minutes');
        $this->migrator->deleteIfExists('suivi.retention_days');
    }
};
