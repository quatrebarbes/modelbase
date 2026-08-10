<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ConnectionAvailability;
use Quatrebarbes\Modelbase\Support\ConnectionRepository;
use Quatrebarbes\Modelbase\Support\EloquentModelFinder;
use Quatrebarbes\Modelbase\Tests\TestCase;

class ConnectionRepositoryTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Remplace entièrement `database.connections` (plutôt que de s'ajouter
        // au squelette par défaut de Testbench, sqlite/mysql/pgsql/sqlsrv),
        // en conservant `testing` : la connexion sqlite en mémoire injectée
        // par Testbench pour porter le schéma migré du test en cours (cf.
        // tests/TestCase.php), exclue du listing comme dans
        // ConnectionListingTest.
        $app['config']->set('database.connections', [
            'testing' => $app['config']->get('database.connections.testing'),
            'Zebra' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'alpha' => ['driver' => 'mysql'],
            'excluded' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        $app['config']->set('modelbase.excluded_connections', ['excluded', 'testing']);
    }

    public function test_all_excludes_configured_connections_and_sorts_by_name_case_insensitively(): void
    {
        $repository = app(ConnectionRepository::class);

        $this->assertSame(
            [
                ['name' => 'alpha', 'driver' => 'mysql'],
                ['name' => 'Zebra', 'driver' => 'sqlite'],
            ],
            $repository->all()
        );
    }

    public function test_all_exposes_only_name_and_driver(): void
    {
        $repository = app(ConnectionRepository::class);

        foreach ($repository->all() as $connection) {
            $this->assertSame(['name', 'driver'], array_keys($connection));
        }
    }

    public function test_status_is_null_for_an_unknown_connection(): void
    {
        $repository = app(ConnectionRepository::class);

        $this->assertNull($repository->status('does-not-exist'));
    }

    public function test_status_is_null_for_an_excluded_connection(): void
    {
        $repository = app(ConnectionRepository::class);

        $this->assertNull($repository->status('excluded'));
    }

    public function test_status_reports_available_with_model_count_when_the_connection_is_reachable(): void
    {
        $this->mock(ConnectionAvailability::class)->shouldReceive('isAvailable')->with('alpha')->andReturn(true);
        $this->mock(EloquentModelFinder::class)->shouldReceive('forConnection')->with('alpha')->andReturn(['A', 'B']);

        $repository = app(ConnectionRepository::class);

        $this->assertSame(['status' => 'available', 'model_count' => 2], $repository->status('alpha'));
    }

    public function test_status_reports_unavailable_with_a_null_model_count_when_the_connection_is_unreachable(): void
    {
        $this->mock(ConnectionAvailability::class)->shouldReceive('isAvailable')->with('alpha')->andReturn(false);
        $this->mock(EloquentModelFinder::class)->shouldNotReceive('forConnection');

        $repository = app(ConnectionRepository::class);

        $this->assertSame(['status' => 'unavailable', 'model_count' => null], $repository->status('alpha'));
    }
}
