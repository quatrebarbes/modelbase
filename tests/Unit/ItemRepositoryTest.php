<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ColumnIntrospector;
use Quatrebarbes\Modelbase\Support\EloquentModelFinder;
use Quatrebarbes\Modelbase\Support\ItemRepository;
use Quatrebarbes\Modelbase\Support\ModelResolver;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ItemRepositoryTest extends TestCase
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

        Schema::connection('primary')->create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::connection('primary')->create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->string('name');
            $table->string('description')->nullable();
        });

        DB::connection('primary')->table('categories')->insert(['id' => 1, 'name' => 'Tools']);

        DB::connection('primary')->table('products')->insert([
            ['category_id' => 1, 'name' => 'Hammer', 'description' => null],
            ['category_id' => 99, 'name' => 'Orphan', 'description' => ''],
        ]);

        $this->putModel('Category', 'categories');
        $this->putModel('Product', 'products');
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

    private function repository(): ItemRepository
    {
        $finder = new EloquentModelFinder;

        return new ItemRepository(new ModelResolver($finder), $finder, new ColumnIntrospector);
    }

    public function test_it_paginates_items_of_a_model(): void
    {
        $page = $this->repository()->paginate('primary', 'Product', 1, 1);

        $this->assertCount(1, $page['data']);
        $this->assertSame(2, $page['meta']['total']);
        $this->assertSame(1, $page['meta']['current_page']);
        $this->assertSame(2, $page['meta']['last_page']);
    }

    public function test_it_returns_no_items_without_error_for_an_empty_model(): void
    {
        DB::connection('primary')->table('products')->delete();

        $page = $this->repository()->paginate('primary', 'Product', 1, 15);

        $this->assertSame([], $page['data']);
        $this->assertSame(0, $page['meta']['total']);
    }

    public function test_find_returns_null_for_an_unknown_item(): void
    {
        $this->assertNull($this->repository()->find('primary', 'Product', '404'));
    }

    public function test_find_decorates_a_valid_foreign_key_as_navigable(): void
    {
        $item = $this->repository()->find('primary', 'Product', '1');

        $values = collect($item['values'])->keyBy('column');

        $this->assertSame('foreign_key', $values['category_id']['type']);
        $this->assertSame([
            'table' => 'categories',
            'model' => 'Category',
            'navigable' => true,
        ], $values['category_id']['foreign_key']);
    }

    public function test_find_flags_a_broken_foreign_key_as_not_navigable(): void
    {
        $item = $this->repository()->find('primary', 'Product', '2');

        $values = collect($item['values'])->keyBy('column');

        $this->assertFalse($values['category_id']['foreign_key']['navigable']);
    }

    public function test_find_distinguishes_null_from_empty_string(): void
    {
        $item = $this->repository()->find('primary', 'Product', '1');
        $values = collect($item['values'])->keyBy('column');

        $this->assertTrue($values['description']['is_null']);
        $this->assertNull($values['description']['value']);

        $item = $this->repository()->find('primary', 'Product', '2');
        $values = collect($item['values'])->keyBy('column');

        $this->assertFalse($values['description']['is_null']);
        $this->assertSame('', $values['description']['value']);
    }
}
