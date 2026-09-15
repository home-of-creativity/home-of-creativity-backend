<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\FakesOdooDocuments;

abstract class TestCase extends BaseTestCase
{
    use FakesOdooDocuments;

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $driver = (string) config('database.default');
        if (in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
            throw new \RuntimeException(
                "Tests refused to run against {$driver}. phpunit.xml must force sqlite :memory: so local data is not wiped."
            );
        }
    }
}
