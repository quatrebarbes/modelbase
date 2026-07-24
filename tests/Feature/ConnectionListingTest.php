<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

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

    public function test_it_lists_available_and_unavailable_connections(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint());

        $response->assertOk();
        $response->assertJsonFragment([
            'name' => 'reachable',
            'driver' => 'sqlite',
            'status' => 'available',
            'model_count' => 0,
        ]);
        $response->assertJsonFragment([
            'name' => 'unreachable',
            'driver' => 'mysql',
            'status' => 'unavailable',
            'model_count' => null,
        ]);
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
            $this->assertSame(
                ['name', 'driver', 'status', 'model_count'],
                array_keys($connection)
            );
        }
    }

    public function test_status_is_recalculated_on_every_call_without_caching(): void
    {
        $user = UserFactory::new()->create();

        config(['database.connections.flaky' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]]);

        $this->actingAs($user)->getJson($this->endpoint())
            ->assertOk()
            ->assertJsonFragment(['name' => 'flaky', 'status' => 'available']);

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

        $this->actingAs($user)->getJson($this->endpoint())
            ->assertOk()
            ->assertJsonFragment(['name' => 'flaky', 'status' => 'unavailable', 'model_count' => null]);
    }
}
