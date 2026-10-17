<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        /*
         * Les adresses de l'administration sont VIDES par defaut : tant que
         * personne ne les a choisies, aucun courriel ne part de ce cote, et
         * le comportement est celui d'avant. Mieux qu'une adresse devinee,
         * qui enverrait les commandes dans le vide.
         */
        $this->migrator->add('notification.emails_commande', '');

        /*
         * Les deux copies sont ALLUMEES, elles.
         *
         * C'est ce que le proprietaire a demande — « le client aussi de
         * recevoir une copie par email et le restaurant egalement » — et
         * les deux adresses sont connues sans reglage : celle du compte et
         * celle du restaurateur. Rien a saisir pour que ca marche.
         */
        $this->migrator->add('notification.copie_client', true);
        $this->migrator->add('notification.copie_restaurant', true);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('notification.emails_commande');
        $this->migrator->deleteIfExists('notification.copie_client');
        $this->migrator->deleteIfExists('notification.copie_restaurant');
    }
};
