<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('rental.deposit_percentage', 10.0);
        $this->migrator->add('rental.refund_percentage', 100.0);
        $this->migrator->add('rental.minimum_hours', 1.0);
        $this->migrator->add('rental.pickup_code_max_attempts', 3);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('rental.deposit_percentage');
        $this->migrator->deleteIfExists('rental.refund_percentage');
        $this->migrator->deleteIfExists('rental.minimum_hours');
        $this->migrator->deleteIfExists('rental.pickup_code_max_attempts');
    }
};
