<?php

namespace Quatrebarbes\Modelbase\Tests;

use Quatrebarbes\Modelbase\ModelbaseServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ModelbaseServiceProvider::class,
        ];
    }

    /**
     * Charge la table `users` par défaut de Testbench : nécessaire pour
     * authentifier des utilisateurs dans les tests Feature (EX-101/EX-103).
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }
}
