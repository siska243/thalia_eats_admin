<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.mysql.database');

        if (! str_ends_with((string) $database, '_test')) {
            $this->fail(
                "Refus de lancer les tests sur la base « {$database} » : le nom doit se terminer par « _test ». ".
                'Vérifiez .env.testing et la variable DB_DATABASE de phpunit.xml.'
            );
        }
    }
}
