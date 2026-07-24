<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\EloquentModelFinder;
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
        $repository = new ModelRepository(app(EloquentModelFinder::class));

        $models = collect($repository->forConnection('primary'))->keyBy('name');

        $this->assertSame('widgets', $models['Sprocket']['table']);
        $this->assertSame(1, $models['Sprocket']['item_count']);
        $this->assertSame(4, $models['Sprocket']['column_count']);
    }

    public function test_it_filters_by_name_case_insensitively(): void
    {
        $repository = new ModelRepository(app(EloquentModelFinder::class));

        $names = collect($repository->forConnection('primary', 'gad'))->pluck('name')->values()->all();

        $this->assertSame(['Gadget'], $names);
    }

    public function test_it_filters_by_table_name_case_insensitively(): void
    {
        $repository = new ModelRepository(app(EloquentModelFinder::class));

        $names = collect($repository->forConnection('primary', 'COG'))->pluck('name')->values()->all();

        $this->assertSame(['Gadget'], $names);
    }

    public function test_a_blank_search_does_not_filter_anything(): void
    {
        $repository = new ModelRepository(app(EloquentModelFinder::class));

        $names = collect($repository->forConnection('primary', ''))->pluck('name')->values()->all();

        $this->assertCount(2, $names);
    }
}
