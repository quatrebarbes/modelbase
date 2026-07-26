<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use PDO;
use Quatrebarbes\Modelbase\Support\ConnectionAvailability;
use Quatrebarbes\Modelbase\Tests\TestCase;
use ReflectionMethod;

class ConnectionAvailabilityTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.reachable', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        // Port sur lequel rien n'écoute : échec de connexion immédiat
        // (connexion refusée), sans dépendre d'un service externe ni d'un
        // délai d'attente long — cf. ConnectionListingTest.
        $app['config']->set('database.connections.unreachable', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
        ]);
    }

    public function test_default_connection_timeout_is_three_seconds(): void
    {
        $this->assertSame(3, config('modelbase.connection_timeout'));
    }

    public function test_it_merges_the_configured_timeout_into_existing_mysql_options(): void
    {
        $result = $this->withTimeout([
            'driver' => 'mysql',
            'options' => ['some_other_option' => 'value'],
        ]);

        $this->assertSame(
            ['some_other_option' => 'value', PDO::ATTR_TIMEOUT => 3],
            $result['options']
        );
    }

    public function test_it_does_not_override_an_explicit_pdo_timeout_already_configured(): void
    {
        $result = $this->withTimeout([
            'driver' => 'mariadb',
            'options' => [PDO::ATTR_TIMEOUT => 42],
        ]);

        $this->assertSame([PDO::ATTR_TIMEOUT => 42], $result['options']);
    }

    public function test_it_sets_a_login_timeout_for_sqlsrv(): void
    {
        $result = $this->withTimeout(['driver' => 'sqlsrv']);

        $this->assertSame(3, $result['login_timeout']);
    }

    public function test_it_leaves_a_driver_without_a_timeout_hook_untouched(): void
    {
        $config = ['driver' => 'pgsql', 'host' => '127.0.0.1'];

        $this->assertSame($config, $this->withTimeout($config));
    }

    public function test_it_never_mutates_the_shared_application_config(): void
    {
        $original = ['some_other_option' => 'value'];
        config(['database.connections.unreachable.options' => $original]);

        $this->assertFalse(app(ConnectionAvailability::class)->isAvailable('unreachable'));

        $this->assertSame($original, config('database.connections.unreachable.options'));
    }

    public function test_it_still_reports_available_connections_correctly(): void
    {
        $availability = app(ConnectionAvailability::class);

        $this->assertTrue($availability->isAvailable('reachable'));
        $this->assertFalse($availability->isAvailable('unreachable'));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withTimeout(array $config): array
    {
        $method = new ReflectionMethod(ConnectionAvailability::class, 'withTimeout');

        return $method->invoke(app(ConnectionAvailability::class), $config);
    }
}
