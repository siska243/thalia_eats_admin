<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        /*
         * Une commande qui n'avance plus bloque son client : la creation
         * refuse tant qu'une commande n'est pas reglee. Toutes les heures
         * plutot qu'une fois par jour, pour qu'il ne reste pas bloque
         * jusqu'a vingt-quatre heures de plus une fois le delai atteint.
         *
         * `withoutOverlapping` : si un passage s'attarde sur une grosse
         * table, le suivant attend au lieu d'annuler les memes lignes deux
         * fois.
         */
        $schedule->command('commandes:annuler-abandonnees')
            ->hourly()
            ->withoutOverlapping();

        /*
         * L'expiration des reservations et des commandes non reglees.
         *
         * Toutes les minutes, parce que le delai est de quinze : passer a
         * l'heure ferait attendre un client jusqu'a une heure de plus avant
         * que l'administration cesse d'afficher sa commande morte.
         *
         * Le passage est court — il ne touche que les lignes deja echues —
         * mais `withoutOverlapping` reste indispensable a cette frequence :
         * sans lui, un passage qui s'attarde verrait le suivant demarrer et
         * les deux eteindraient les memes lignes.
         */
        $schedule->command('expirations:traiter')
            ->everyMinute()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
