<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Qui est prevenu quand une commande est payee.
 *
 * Avant, une commande payee n'envoyait qu'UN signal : une notification push
 * au restaurateur, et seulement s'il avait un jeton. Ni l'administration ni
 * le client n'apprenaient rien. Une commande pouvait donc arriver sans que
 * personne au bureau le sache.
 *
 * Les adresses vivent en reglage et non dans le code : elles changent avec
 * les gens, et cela ne doit pas demander un deploiement.
 *
 * VIDE, AUCUN COURRIEL D'ADMINISTRATION NE PART, et le reste continue de
 * fonctionner. C'est l'etat d'avant : mieux qu'une adresse devinee, qui
 * enverrait les commandes dans le vide.
 */
class NotificationSettings extends Settings
{
    /**
     * Les adresses de l'administration, separees par une virgule, un
     * point-virgule ou un retour a la ligne.
     *
     * Une chaine et non un tableau : le champ du back-office est une zone de
     * texte, et un tableau JSON y serait saisi a la main, avec ses crochets
     * et ses guillemets. `destinatairesAdmin()` fait la conversion, une
     * seule fois.
     */
    public string $emails_commande;

    /** Le client recoit une copie de sa commande. */
    public bool $copie_client;

    /** Le restaurant recoit la commande par courriel, en plus du push. */
    public bool $copie_restaurant;

    public static function group(): string
    {
        return 'notification';
    }

    /**
     * Les adresses valides, dedoublonnees.
     *
     * La separation ET la validation vivent ici, une seule fois : une
     * adresse mal saisie dans le back-office ne doit pas faire echouer
     * l'envoi aux autres, ni lever au milieu d'un paiement.
     *
     * @return array<string>
     */
    public static function destinatairesAdmin(): array
    {
        try {
            $brut = app(self::class)->emails_commande;
        } catch (\Throwable) {
            return [];
        }

        return collect(preg_split('/[,;\s]+/', $brut) ?: [])
            ->map(fn (string $adresse): string => trim($adresse))
            ->filter(fn (string $adresse): bool => filter_var($adresse, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    public static function copieClient(): bool
    {
        try {
            return app(self::class)->copie_client;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function copieRestaurant(): bool
    {
        try {
            return app(self::class)->copie_restaurant;
        } catch (\Throwable) {
            return false;
        }
    }
}
