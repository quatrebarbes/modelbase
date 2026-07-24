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
     * Les routes du plug-in passent par le groupe "web" (session de l'app
     * hôte, cf. routes/api.php) : `EncryptCookies` y exige une clé
     * applicative, absente par défaut du squelette de test Testbench.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
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
