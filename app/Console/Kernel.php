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
