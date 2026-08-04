<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Tests\TestCase;
use Orchestra\Testbench\Factories\UserFactory;

class ConnectionStatusTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.reachable', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Hôte local sur un port sur lequel rien n'écoute : échec de
        // connexion immédiat (connexion refusée), sans dépendre d'un service
        // externe réel ni d'un délai d'attente long.
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

        $app['config']->set('modelbase.excluded_connections', ['excluded_but_configured', 'testing']);
    }

    private function endpoint(string $connection): string
    {
        return route('modelbase.api.connections.status', ['connection' => $connection]);
    }

    public function test_it_returns_status_and_model_count_for_an_available_connection(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('reachable'));

        $response->assertOk();
        $response->assertExactJson(['status' => 'available', 'model_count' => 0]);
    }

    public function test_it_returns_unavailable_status_without_model_count(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('unreachable'));

        $response->assertOk();
        $response->assertExactJson(['status' => 'unavailable', 'model_count' => null]);
    }

    public function test_it_returns_404_for_an_unknown_connection(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('does-not-exist'));

        $response->assertNotFound();
    }

    public function test_it_returns_404_for_an_excluded_connection(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('excluded_but_configured'));

        $response->assertNotFound();
    }

    public function test_status_is_recalculated_on_every_call_without_caching(): void
    {
        $user = UserFactory::new()->create();

        config(['database.connections.flaky' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]]);

        $this->actingAs($user)->getJson($this->endpoint('flaky'))
            ->assertOk()
            ->assertJsonFragment(['status' => 'available']);

        // Simule une connexion devenue injoignable entre deux affichages : si
        // le statut était mis en cache (PDO déjà résolu lors du premier
        // appel), ce second appel afficherait encore "available" malgré cette
        // nouvelle configuration erronée (EX-208).
        config(['database.connections.flaky' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
        ]]);

        $this->actingAs($user)->getJson($this->endpoint('flaky'))
            ->assertOk()
            ->assertExactJson(['status' => 'unavailable', 'model_count' => null]);
    }
}
