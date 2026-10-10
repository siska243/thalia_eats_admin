<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Trente minutes : le temps qu'il faut pour joindre un chauffeur et
        // le faire partir. C'est une regle commerciale, pas une constante :
        // elle se desserre un jour de pluie et se resserre quand la flotte
        // grandit, sans livraison.
        $this->migrator->add('rental.lead_time_minutes', 30);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('rental.lead_time_minutes');
    }
};
