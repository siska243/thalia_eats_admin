<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $database = $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');

        // Preventif, et non un constat apres coup : cette methode s'execute
        // avant setUpTraits(), donc avant que RefreshDatabase ou
        // DatabaseTruncation n'aient touche quoi que ce soit.
        if (! str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException(
                "Refus de lancer les tests sur la base « {$database} » : le nom doit se terminer par « _test ». ".
                'Verifiez votre .env.testing.'
            );
        }

        return $app;
    }
}
