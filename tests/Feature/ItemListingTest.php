<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Factories\UserFactory;

class ItemListingTest extends TestCase
{
    private string $sqlitePath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Cf. ModelListingTest : fichier sur disque plutôt que ':memory:',
        // EnsureConnectionIsNavigable purgeant la connexion avant de servir
        // la requête (EX-204/EX-208).
        $this->sqlitePath = tempnam(sys_get_temp_dir(), 'modelbase_test_');

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath,
            // Cf. ColumnIntrospectorTest : sans ce flag, une colonne JSON
            // sqlite est stockée en 'text', indiscernable d'une colonne
            // string à l'introspection.
            'use_native_json' => true,
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
            $table->decimal('price');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::connection('primary')->create('empties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        DB::connection('primary')->table('categories')->insert(['id' => 1, 'name' => 'Tools']);

        DB::connection('primary')->table('products')->insert([
            'category_id' => 1,
            'name' => 'Hammer',
            'price' => 9.99,
            'metadata' => json_encode(['weight_kg' => 1.2]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('primary')->table('products')->insert([
            'category_id' => 99,
            'name' => 'Orphan',
            'price' => 1,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putModel('ListingCategory', 'categories', ['name']);
        $this->putModel('ListingProduct', 'products', ['category_id', 'name', 'price', 'metadata']);
        $this->putModel('ListingBlank', 'empties', ['name']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Models'));

        if (isset($this->sqlitePath) && file_exists($this->sqlitePath)) {
            unlink($this->sqlitePath);
        }

        parent::tearDown();
    }

    /**
     * @param  array<int, string>  $fillable
     */
    private function putModel(string $class, string $table, array $fillable): void
    {
        $namespace = app()->getNamespace();
        $fillableList = collect($fillable)->map(fn (string $column) => "'{$column}'")->implode(', ');

        File::put(app_path("Models/{$class}.php"), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;

        class {$class} extends Model
        {
            protected \$connection = 'primary';

            protected \$table = '{$table}';

            protected \$fillable = [{$fillableList}];
        }
        PHP);

        require_once app_path("Models/{$class}.php");
    }

    private function indexUrl(string $model, array $query = []): string
    {
        $url = route('modelbase.api.connections.models.items.index', [
            'connection' => 'primary',
            'model' => $model,
        ]);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    private function showUrl(string $model, string $item): string
    {
        return route('modelbase.api.connections.models.items.show', [
            'connection' => 'primary',
            'model' => $model,
            'item' => $item,
        ]);
    }

    public function test_it_paginates_the_items_of_a_model(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->indexUrl('ListingProduct', ['per_page' => 1, 'page' => 1]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.total', 2);
        $response->assertJsonPath('meta.last_page', 2);

        $response = $this->actingAs($user)->getJson($this->indexUrl('ListingProduct', ['per_page' => 1, 'page' => 2]));
        $response->assertJsonPath('meta.current_page', 2);
    }

    public function test_it_returns_an_empty_list_without_error_for_a_model_with_no_items(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->indexUrl('ListingBlank'));

        $response->assertOk();
        $response->assertJson(['data' => []]);
    }

    public function test_navigation_is_blocked_for_a_model_not_declared_on_the_connection(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->indexUrl('DoesNotExist'));

        $response->assertStatus(404);
    }

    public function test_it_returns_the_full_detail_of_an_item_with_typed_values(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->showUrl('ListingProduct', '1'));

        $response->assertOk();
        $values = collect($response->json('data.values'))->keyBy('column');

        $this->assertSame('number', $values['price']['type']);
        $this->assertSame('json', $values['metadata']['type']);
        $this->assertSame('foreign_key', $values['category_id']['type']);
    }

    public function test_a_valid_foreign_key_resolves_to_its_referenced_model_as_navigable(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->showUrl('ListingProduct', '1'));

        $values = collect($response->json('data.values'))->keyBy('column');

        $this->assertSame([
            'table' => 'categories',
            'model' => 'ListingCategory',
            'navigable' => true,
        ], $values['category_id']['foreign_key']);
    }

    public function test_a_broken_foreign_key_is_flagged_as_not_navigable(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->showUrl('ListingProduct', '2'));

        $values = collect($response->json('data.values'))->keyBy('column');

        $this->assertFalse($values['category_id']['foreign_key']['navigable']);
    }

    public function test_a_null_value_is_distinguished_from_an_empty_string(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->showUrl('ListingProduct', '2'));

        $values = collect($response->json('data.values'))->keyBy('column');

        $this->assertTrue($values['metadata']['is_null']);
        $this->assertNull($values['metadata']['value']);
    }

    public function test_it_returns_404_for_an_unknown_item(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->showUrl('ListingProduct', '999'));

        $response->assertStatus(404);
    }
}
