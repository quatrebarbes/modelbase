<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ColumnIntrospector;
use Quatrebarbes\Modelbase\Support\DatabaseErrorTranslator;
use Quatrebarbes\Modelbase\Support\EloquentModelFinder;
use Quatrebarbes\Modelbase\Support\ItemRepository;
use Quatrebarbes\Modelbase\Support\ItemValidationException;
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
            $table->string('name')->unique();
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

        return new ItemRepository(new ModelResolver($finder), $finder, new ColumnIntrospector, new DatabaseErrorTranslator);
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

    public function test_columns_flags_the_primary_key_as_technical_and_describes_foreign_keys(): void
    {
        $columns = collect($this->repository()->columns('primary', 'Product'))->keyBy('column');

        $this->assertTrue($columns['id']['technical']);
        $this->assertFalse($columns['name']['technical']);
        $this->assertSame('foreign_key', $columns['category_id']['type']);
        $this->assertSame('Category', $columns['category_id']['foreign_key']['model']);
    }

    public function test_create_inserts_a_new_item_with_the_submitted_values(): void
    {
        $item = $this->repository()->create('primary', 'Product', [
            'category_id' => 1,
            'name' => 'Wrench',
            'description' => 'A tool',
        ]);

        $values = collect($item['values'])->keyBy('column');

        $this->assertSame('Wrench', $values['name']['value']);
        $this->assertSame('A tool', $values['description']['value']);
    }

    public function test_create_ignores_a_submitted_primary_key(): void
    {
        $item = $this->repository()->create('primary', 'Product', [
            'id' => 999,
            'category_id' => 1,
            'name' => 'Wrench',
        ]);

        $this->assertNotSame(999, $item['id']);
    }

    public function test_create_throws_a_validation_exception_for_a_missing_required_column(): void
    {
        $this->expectException(ItemValidationException::class);

        try {
            $this->repository()->create('primary', 'Product', ['category_id' => 1]);
        } catch (ItemValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());

            throw $exception;
        }
    }

    public function test_create_throws_a_validation_exception_for_a_duplicate_unique_column(): void
    {
        $this->expectException(ItemValidationException::class);

        try {
            $this->repository()->create('primary', 'Product', ['category_id' => 1, 'name' => 'Hammer']);
        } catch (ItemValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());

            throw $exception;
        }
    }

    public function test_update_modifies_the_values_of_an_existing_item(): void
    {
        $item = $this->repository()->update('primary', 'Product', '1', ['description' => 'Updated']);

        $values = collect($item['values'])->keyBy('column');
        $this->assertSame('Updated', $values['description']['value']);
    }

    public function test_update_returns_null_for_an_unknown_item(): void
    {
        $this->assertNull($this->repository()->update('primary', 'Product', '404', ['description' => 'x']));
    }

    public function test_update_ignores_a_submitted_primary_key(): void
    {
        $item = $this->repository()->update('primary', 'Product', '1', ['id' => 555, 'description' => 'Updated']);

        $this->assertSame(1, $item['id']);
    }
}
