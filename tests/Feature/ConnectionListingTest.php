<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Support\ConnectionAvailability;
use Quatrebarbes\Modelbase\Support\EloquentModelFinder;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Orchestra\Testbench\Factories\UserFactory;

class ConnectionListingTest extends TestCase
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

        // `testing` est la connexion sqlite en mémoire injectée par Testbench
        // lui-même pour porter le schéma migré du test en cours (cf.
        // tests/TestCase.php) : elle n'a rien d'une connexion applicative et
        // doit rester hors du listing pour ne pas être purgée par
        // ConnectionAvailability pendant que le test l'utilise encore.
        $app['config']->set('modelbase.excluded_connections', ['excluded_but_configured', 'testing']);
    }

    private function endpoint(): string
    {
        return route('modelbase.api.connections.index');
    }

    public function test_it_lists_connection_names_and_drivers(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint());

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'reachable', 'driver' => 'sqlite']);
        $response->assertJsonFragment(['name' => 'unreachable', 'driver' => 'mysql']);
    }

    public function test_it_excludes_connections_listed_in_modelbase_config(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint());

        $response->assertOk();
        $response->assertJsonMissing(['name' => 'excluded_but_configured']);
    }

    public function test_it_does_not_expose_sensitive_connection_details(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint());

        $response->assertOk();

        foreach ($response->json('data') as $connection) {
            $this->assertSame(['name', 'driver'], array_keys($connection));
        }
    }

    public function test_it_lists_connections_without_resolving_status_or_model_count(): void
    {
        // EX-209 : le listing brut ne doit plus jamais résoudre le statut ni
        // le comptage de modèles — si `index()` en dépendait encore, ces
        // deux mocks échoueraient le test dès leur premier appel, plutôt que
        // de dépendre d'un délai de connexion réel pour le détecter.
        $this->mock(ConnectionAvailability::class)->shouldNotReceive('isAvailable');
        $this->mock(EloquentModelFinder::class)->shouldNotReceive('forConnection');

        $user = UserFactory::new()->create();

        $this->actingAs($user)->getJson($this->endpoint())->assertOk();
    }
}
