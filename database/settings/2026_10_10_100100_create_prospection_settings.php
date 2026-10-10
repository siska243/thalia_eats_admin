<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Vide par defaut : tant que personne n'a choisi l'adresse, aucun
        // e-mail ne part et le back-office reste la seule porte. Mieux qu'une
        // adresse devinee, qui enverrait les demandes dans le vide.
        $this->migrator->add('prospection.email_destinataire', '');
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('prospection.email_destinataire');
    }
};
