<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Http\Middleware\EnsureConnectionIsNavigable;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Routing\Router;

class ConnectionNavigabilityTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.reachable', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $app['config']->set('database.connections.unreachable', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
        ]);

        $app['config']->set('database.connections.excluded_but_configured', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $app['config']->set('modelbase.excluded_connections', ['excluded_but_configured']);
    }

    /**
     * Sonde exerçant le middleware réellement destiné aux futures routes
     * imbriquées `/connections/{connection}/...` (modules 3-4), avant même
     * que celles-ci n'existent — même approche que AuthenticationTest en
     * Phase 1 pour le middleware Authenticate.
     */
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware(EnsureConnectionIsNavigable::class)
            ->get('/__modelbase-test/connections/{connection}/probe', fn () => response()->json(['ok' => true]));
    }

    private function probe(string $connection): string
    {
        return "/__modelbase-test/connections/{$connection}/probe";
    }

    public function test_navigation_is_allowed_for_an_available_connection(): void
    {
        $this->getJson($this->probe('reachable'))
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_navigation_is_blocked_for_an_unavailable_connection(): void
    {
        $this->getJson($this->probe('unreachable'))
            ->assertStatus(409);
    }

    public function test_navigation_is_blocked_for_an_unknown_connection(): void
    {
        $this->getJson($this->probe('does-not-exist'))
            ->assertStatus(404);
    }

    public function test_navigation_is_blocked_for_an_excluded_connection(): void
    {
        $this->getJson($this->probe('excluded_but_configured'))
            ->assertStatus(404);
    }
}
