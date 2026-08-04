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

        // Phase 17 : cache de découverte des modèles désactivé par défaut en
        // test (résultats attendus immédiatement après écriture d'un fichier
        // de modèle factice) ; réactivé explicitement par les tests qui
        // vérifient le cache lui-même. Store 'array' pour ne dépendre
        // d'aucune table de cache migrée.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('modelbase.model_discovery_cache_ttl', 0);
    }

    /**
     * Charge la table `users` par défaut de Testbench : nécessaire pour
     * authentifier des utilisateurs dans les tests Feature (EX-101/EX-103).
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    /**
     * `SQLiteGrammar::typeJson()` ignore `use_native_json` sur Laravel 11
     * (toujours 'text') : le flag n'est respecté qu'à partir de Laravel 12,
     * ce qui rend une colonne JSON sqlite indiscernable d'une colonne string
     * à l'introspection sous Laravel 11 — propre à sqlite, mysql/pgsql
     * exposent nativement un type 'json' quelle que soit la version.
     */
    protected function skipUnlessSqliteSupportsNativeJson(): void
    {
        if (version_compare($this->app->version(), '12', '<')) {
            $this->markTestSkipped('Détection JSON sqlite non supportée sur Laravel 11 (use_native_json ignoré par SQLiteGrammar) — mysql/pgsql non affectés.');
        }
    }
}
