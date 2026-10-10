<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Vide : il n'y a pas de numero plausible par defaut, et en inventer
        // un ferait appeler quelqu'un qui n'est pas le service.
        $this->migrator->add('rental.whatsapp_number', '');
        $this->migrator->add('rental.brand_food', 'Thalia Eats');
        $this->migrator->add('rental.brand_rental', 'Thalia Trans');
        $this->migrator->add('rental.out_of_zone_message', 'Nous ne desservons pas encore cette commune. Contactez Thalia Trans pour organiser votre course.');
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('rental.whatsapp_number');
        $this->migrator->deleteIfExists('rental.brand_food');
        $this->migrator->deleteIfExists('rental.brand_rental');
        $this->migrator->deleteIfExists('rental.out_of_zone_message');
    }
};
