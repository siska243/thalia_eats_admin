<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Ou partent les demandes de partenariat.
 *
 * L'adresse vit en reglage et non dans le code : elle change avec la personne
 * qui fait la prospection, et cela ne doit pas demander un deploiement.
 * Vide, aucun e-mail n'est envoye et le back-office reste la seule porte.
 */
class ProspectionSettings extends Settings
{
    public string $email_destinataire;

    public static function group(): string
    {
        return 'prospection';
    }

    /** Lue par le controleur. Absente des reglages, elle vaut vide. */
    public static function destinataire(): string
    {
        try {
            return app(self::class)->email_destinataire;
        } catch (\Throwable) {
            return '';
        }
    }
}
