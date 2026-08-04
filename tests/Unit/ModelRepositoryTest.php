<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ItemCountEstimator;
use Quatrebarbes\Modelbase\Support\ModelRepository;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ModelRepositoryTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
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

        Schema::connection('primary')->create('cogs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        DB::connection('primary')->table('widgets')->insert(['name' => 'Foo', 'created_at' => now(), 'updated_at' => now()]);

        $this->putModel('Sprocket', 'widgets');
        $this->putModel('Gadget', 'cogs');
        $this->putModel('Ghost', 'ghosts');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Models'));

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

    public function test_it_describes_each_model_with_table_item_and_column_counts(): void
    {
        $repository = app(ModelRepository::class);

        $models = collect($repository->forConnection('primary'))->keyBy('name');

        $this->assertSame('widgets', $models['Sprocket']['table']);
        $this->assertSame('1', $models['Sprocket']['item_count']);
        $this->assertSame(1, $models['Sprocket']['item_count_raw']);
        $this->assertSame(4, $models['Sprocket']['column_count']);
    }

    public function test_it_filters_by_name_case_insensitively(): void
    {
        $repository = app(ModelRepository::class);

        $names = collect($repository->forConnection('primary', 'gad'))->pluck('name')->values()->all();

        $this->assertSame(['Gadget'], $names);
    }

    public function test_it_filters_by_table_name_case_insensitively(): void
    {
        $repository = app(ModelRepository::class);

        $names = collect($repository->forConnection('primary', 'COG'))->pluck('name')->values()->all();

        $this->assertSame(['Gadget'], $names);
    }

    public function test_a_blank_search_does_not_filter_anything(): void
    {
        $repository = app(ModelRepository::class);

        $names = collect($repository->forConnection('primary', ''))->pluck('name')->values()->all();

        $this->assertCount(3, $names);
    }

    public function test_a_model_whose_table_does_not_exist_is_listed_with_zero_counts(): void
    {
        $repository = app(ModelRepository::class);

        $models = collect($repository->forConnection('primary'))->keyBy('name');

        $this->assertSame('ghosts', $models['Ghost']['table']);
        $this->assertSame('0', $models['Ghost']['item_count']);
        $this->assertSame(0, $models['Ghost']['item_count_raw']);
        $this->assertSame(0, $models['Ghost']['column_count']);
    }

    public function test_it_uses_the_engine_estimate_when_it_is_above_the_exact_count_threshold(): void
    {
        // 'widgets' ne contient réellement qu'une ligne : si le résultat
        // affiché reflète l'estimation (5000 -> "5K") plutôt que le COUNT(*)
        // réel ("1"), c'est bien l'estimation moteur qui a été utilisée.
        $this->mock(ItemCountEstimator::class)->shouldReceive('estimate')->andReturn(5_000);

        $models = collect(app(ModelRepository::class)->forConnection('primary'))->keyBy('name');

        $this->assertSame('5K', $models['Sprocket']['item_count']);
        $this->assertSame(5_000, $models['Sprocket']['item_count_raw']);
    }

    public function test_it_falls_back_to_an_exact_count_when_the_engine_estimate_is_below_the_threshold(): void
    {
        // Une estimation à 0 (stats moteur pas encore rafraîchies, cas
        // fréquent en pgsql sans ANALYZE récent) ne doit jamais être prise
        // pour argent comptant sous le seuil : on retombe sur un COUNT(*).
        $this->mock(ItemCountEstimator::class)->shouldReceive('estimate')->andReturn(0);

        $models = collect(app(ModelRepository::class)->forConnection('primary'))->keyBy('name');

        $this->assertSame('1', $models['Sprocket']['item_count']);
    }

    public function test_it_falls_back_to_an_exact_count_when_the_engine_has_no_estimate(): void
    {
        $this->mock(ItemCountEstimator::class)->shouldReceive('estimate')->andReturn(null);

        $models = collect(app(ModelRepository::class)->forConnection('primary'))->keyBy('name');

        $this->assertSame('1', $models['Sprocket']['item_count']);
    }
}
