<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Factories\UserFactory;

class ItemMutationTest extends TestCase
{
    private string $sqlitePath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Cf. ItemListingTest/ModelListingTest : fichier sur disque plutôt que
        // ':memory:', EnsureConnectionIsNavigable purgeant la connexion avant
        // de servir la requête (EX-204/EX-208) — les routes de mutation
        // passent par le même middleware imbriqué (EnsureModelIsNavigable).
        $this->sqlitePath = tempnam(sys_get_temp_dir(), 'modelbase_test_');

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath,
            'use_native_json' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(app_path('Models'));

        Schema::connection('primary')->create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
        });

        Schema::connection('primary')->create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->string('name');
            $table->string('sku')->unique();
            $table->decimal('price');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        DB::connection('primary')->table('categories')->insert(['id' => 1, 'name' => 'Tools']);

        $this->putModel('Category', 'categories');
        $this->putModel('Product', 'products');
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

    private function columnsUrl(string $model): string
    {
        return route('modelbase.api.connections.models.columns.index', [
            'connection' => 'primary',
            'model' => $model,
        ]);
    }

    private function storeUrl(string $model): string
    {
        return route('modelbase.api.connections.models.items.store', [
            'connection' => 'primary',
            'model' => $model,
        ]);
    }

    private function updateUrl(string $model, string $item): string
    {
        return route('modelbase.api.connections.models.items.update', [
            'connection' => 'primary',
            'model' => $model,
            'item' => $item,
        ]);
    }

    private function createProduct(array $overrides = []): int
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->storeUrl('Product'), array_merge([
            'category_id' => 1,
            'name' => 'Hammer',
            'sku' => 'SKU-1',
            'price' => 9.99,
        ], $overrides));

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    public function test_columns_describes_the_schema_including_technical_and_foreign_key_columns(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->columnsUrl('Product'));

        $response->assertOk();
        $columns = collect($response->json('data'))->keyBy('column');

        $this->assertTrue($columns['id']['technical']);
        $this->assertTrue($columns['created_at']['technical']);
        $this->assertTrue($columns['updated_at']['technical']);
        $this->assertFalse($columns['name']['technical']);
        $this->assertSame('foreign_key', $columns['category_id']['type']);
        $this->assertSame('Category', $columns['category_id']['foreign_key']['model']);
    }

    public function test_it_creates_an_item_with_the_submitted_values(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->storeUrl('Product'), [
            'category_id' => 1,
            'name' => 'Hammer',
            'sku' => 'SKU-1',
            'price' => 9.99,
        ]);

        $response->assertCreated();
        $values = collect($response->json('data.values'))->keyBy('column');

        $this->assertSame('Hammer', $values['name']['value']);
        $this->assertSame('SKU-1', $values['sku']['value']);
        $this->assertFalse($values['created_at']['is_null']);
        $this->assertFalse($values['updated_at']['is_null']);
    }

    public function test_it_ignores_submitted_technical_columns_on_create(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->storeUrl('Product'), [
            'id' => 999,
            'created_at' => '2000-01-01 00:00:00',
            'category_id' => 1,
            'name' => 'Hammer',
            'sku' => 'SKU-1',
            'price' => 9.99,
        ]);

        $response->assertCreated();
        $values = collect($response->json('data.values'))->keyBy('column');

        $this->assertNotSame(999, $response->json('data.id'));
        $this->assertNotSame('2000-01-01 00:00:00', $values['created_at']['value']);
    }

    public function test_create_returns_a_validation_error_for_a_missing_required_column(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->storeUrl('Product'), [
            'category_id' => 1,
            'sku' => 'SKU-1',
            'price' => 9.99,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['name']]);
    }

    public function test_create_returns_a_validation_error_for_a_duplicate_unique_column(): void
    {
        $this->createProduct(['sku' => 'SKU-DUP']);
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->storeUrl('Product'), [
            'category_id' => 1,
            'name' => 'Another',
            'sku' => 'SKU-DUP',
            'price' => 5,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['sku']]);
    }

    public function test_it_updates_the_values_of_an_existing_item(): void
    {
        $id = $this->createProduct();
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->patchJson($this->updateUrl('Product', (string) $id), [
            'name' => 'Updated hammer',
        ]);

        $response->assertOk();
        $values = collect($response->json('data.values'))->keyBy('column');

        $this->assertSame('Updated hammer', $values['name']['value']);
        $this->assertSame('SKU-1', $values['sku']['value']);
    }

    public function test_it_ignores_submitted_technical_columns_on_update(): void
    {
        $id = $this->createProduct();
        $user = UserFactory::new()->create();
        $originalCreatedAt = DB::connection('primary')->table('products')->where('id', $id)->value('created_at');

        $response = $this->actingAs($user)->patchJson($this->updateUrl('Product', (string) $id), [
            'id' => 555,
            'created_at' => '2000-01-01 00:00:00',
            'name' => 'Updated hammer',
        ]);

        $response->assertOk();
        $this->assertSame($id, $response->json('data.id'));

        $values = collect($response->json('data.values'))->keyBy('column');
        $this->assertSame($originalCreatedAt, $values['created_at']['value']);
    }

    public function test_it_returns_404_when_updating_an_unknown_item(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->patchJson($this->updateUrl('Product', '999'), [
            'name' => 'Updated hammer',
        ]);

        $response->assertStatus(404);
    }

    public function test_it_encodes_an_array_value_as_json_for_a_json_column_on_create(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->storeUrl('Product'), [
            'category_id' => 1,
            'name' => 'Hammer',
            'sku' => 'SKU-1',
            'price' => 9.99,
            'metadata' => ['weight_kg' => 1.2],
        ]);

        $response->assertCreated();
        $values = collect($response->json('data.values'))->keyBy('column');

        $this->assertSame(['weight_kg' => 1.2], json_decode($values['metadata']['value'], true));
    }

    public function test_update_returns_a_validation_error_for_a_duplicate_unique_column(): void
    {
        $firstId = $this->createProduct(['sku' => 'SKU-FIRST']);
        $this->createProduct(['sku' => 'SKU-SECOND']);
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->patchJson($this->updateUrl('Product', (string) $firstId), [
            'sku' => 'SKU-SECOND',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['sku']]);
    }
}
