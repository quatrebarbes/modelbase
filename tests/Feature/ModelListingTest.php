<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Factories\UserFactory;

class ModelListingTest extends TestCase
{
    private string $sqlitePath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Fichier sur disque plutôt que ':memory:' : EnsureConnectionIsNavigable
        // purge la connexion (EX-204/EX-208) avant de servir la requête, ce
        // qui reconnecte à une base sqlite en mémoire vierge — la table créée
        // en amont dans ce test disparaîtrait sinon.
        $this->sqlitePath = tempnam(sys_get_temp_dir(), 'modelbase_test_');

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath,
        ]);

        $app['config']->set('database.connections.empty_connection', [
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
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(app_path('Models'));

        Schema::connection('primary')->create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        DB::connection('primary')->table('widgets')->insert([
            ['name' => 'Foo', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bar', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Deux classes Eloquent distinctes pointant vers la même table
        // (EX-301, limite « plusieurs modèles / même table »).
        $this->putModel('Doohickey', 'widgets');
        $this->putModel('DoohickeyAlias', 'widgets');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Models'));

        if (isset($this->sqlitePath) && file_exists($this->sqlitePath)) {
            unlink($this->sqlitePath);
        }

        parent::tearDown();
    }

    private function putModel(string $class, string $table): void
    {
        $namespace = app()->getNamespace();

        File::put(app_path("Models/{$class}.php"), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;

        class {$class} extends Model
        {
            protected \$connection = 'primary';

            protected \$table = '{$table}';
        }
        PHP);

        require_once app_path("Models/{$class}.php");
    }

    private function endpoint(string $connection, array $query = []): string
    {
        $url = route('modelbase.api.connections.models.index', ['connection' => $connection]);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    public function test_it_lists_models_declared_for_the_connection_with_item_and_column_counts(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('primary'));

        $response->assertOk();
        $response->assertJsonFragment([
            'name' => 'Doohickey',
            'table' => 'widgets',
            'item_count' => 2,
            'column_count' => 4,
        ]);
    }

    public function test_multiple_models_on_the_same_table_are_listed_as_distinct_entries(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('primary'));

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Doohickey'));
        $this->assertTrue($names->contains('DoohickeyAlias'));
    }

    public function test_it_returns_an_empty_list_without_error_when_no_model_uses_the_connection(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('empty_connection'));

        $response->assertOk();
        $response->assertJson(['data' => []]);
    }

    public function test_it_filters_the_listing_by_name(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('primary', ['search' => 'Alias']));

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->values()->all();

        $this->assertSame(['DoohickeyAlias'], $names);
    }

    public function test_it_filters_the_listing_by_table_name(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('primary', ['search' => 'widgets']));

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->values()->all();

        $this->assertEqualsCanonicalizing(['Doohickey', 'DoohickeyAlias'], $names);
    }

    public function test_navigation_is_blocked_for_an_unavailable_connection(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('unreachable'));

        $response->assertStatus(409);
    }

    public function test_navigation_is_blocked_for_an_unknown_connection(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('does-not-exist'));

        $response->assertStatus(404);
    }
}
