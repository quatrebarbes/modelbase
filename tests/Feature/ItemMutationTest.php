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
            // Colonne volontairement absente de $fillable (cf. putModel()
            // ci-dessous) pour vérifier EX-464 : une colonne non fillable,
            // hors colonnes techniques (EX-416), est traitée en lecture seule.
            // Déclarée dans $casts (sans effet sur son comportement, un cast
            // 'string' sur une colonne déjà string) uniquement pour rester
            // exposée au sens d'EX-422 (colonnes lues depuis le code hôte) :
            // sans ce cast, colonnesFor() l'exclurait purement et simplement
            // du listing/de la fiche détail, empêchant de vérifier EX-464.
            $table->string('internal_note')->nullable();
            $table->timestamps();
        });

        DB::connection('primary')->table('categories')->insert(['id' => 1, 'name' => 'Tools']);

        $this->putModel('MutationCategory', 'categories', ['name']);
        $this->putModel('MutationProduct', 'products', ['category_id', 'name', 'sku', 'price', 'metadata'], ['internal_note' => 'string']);
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
     * @param  array<string, string>  $casts
     */
    private function putModel(string $class, string $table, array $fillable, array $casts = []): void
    {
        $namespace = app()->getNamespace();
        $fillableList = collect($fillable)->map(fn (string $column) => "'{$column}'")->implode(', ');
        $castsList = collect($casts)->map(fn (string $cast, string $column) => "'{$column}' => '{$cast}'")->implode(', ');

        File::put(app_path("Models/{$class}.php"), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;

        class {$class} extends Model
        {
            protected \$connection = 'primary';

            protected \$table = '{$table}';

            protected \$fillable = [{$fillableList}];

            protected \$casts = [{$castsList}];
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

        $response = $this->actingAs($user)->postJson($this->storeUrl('MutationProduct'), array_merge([
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

        $response = $this->actingAs($user)->getJson($this->columnsUrl('MutationProduct'));

        $response->assertOk();
        $columns = collect($response->json('data'))->keyBy('column');

        $this->assertTrue($columns['id']['technical']);
        $this->assertTrue($columns['created_at']['technical']);
        $this->assertTrue($columns['updated_at']['technical']);
        $this->assertFalse($columns['name']['technical']);
        $this->assertSame('foreign_key', $columns['category_id']['type']);
        $this->assertSame('MutationCategory', $columns['category_id']['foreign_key']['model']);
    }

    /**
     * EX-421/EX-464 : `internal_note` n'est pas dans $fillable (cf. putModel()) —
     * signalée comme non fillable, au même titre qu'une colonne technique
     * pour le rendu en lecture seule côté front.
     */
    public function test_columns_flags_a_non_fillable_column_as_not_fillable(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->columnsUrl('MutationProduct'));
        $columns = collect($response->json('data'))->keyBy('column');

        $this->assertFalse($columns['internal_note']['fillable']);
        $this->assertTrue($columns['name']['fillable']);
    }

    public function test_it_creates_an_item_with_the_submitted_values(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->storeUrl('MutationProduct'), [
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

        $response = $this->actingAs($user)->postJson($this->storeUrl('MutationProduct'), [
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

    /**
     * EX-421/EX-464 : une colonne non fillable côté modèle hôte (ici `internal_note`,
     * cf. putModel()) est ignorée à la création, comme une colonne technique.
     */
    public function test_it_ignores_a_submitted_non_fillable_column_on_create(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->storeUrl('MutationProduct'), [
            'category_id' => 1,
            'name' => 'Hammer',
            'sku' => 'SKU-1',
            'price' => 9.99,
            'internal_note' => 'should not be saved',
        ]);

        $response->assertCreated();
        $values = collect($response->json('data.values'))->keyBy('column');

        $this->assertTrue($values['internal_note']['is_null']);
    }

    public function test_create_returns_a_validation_error_for_a_missing_required_column(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->storeUrl('MutationProduct'), [
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

        $response = $this->actingAs($user)->postJson($this->storeUrl('MutationProduct'), [
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

        $response = $this->actingAs($user)->patchJson($this->updateUrl('MutationProduct', (string) $id), [
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

        $response = $this->actingAs($user)->patchJson($this->updateUrl('MutationProduct', (string) $id), [
            'id' => 555,
            'created_at' => '2000-01-01 00:00:00',
            'name' => 'Updated hammer',
        ]);

        $response->assertOk();
        $this->assertSame($id, $response->json('data.id'));

        $values = collect($response->json('data.values'))->keyBy('column');
        $this->assertSame($originalCreatedAt, $values['created_at']['value']);
    }

    /**
     * EX-421/EX-464 : même principe que test_it_ignores_a_submitted_non_fillable_column_on_create()
     * pour la modification.
     */
    public function test_it_ignores_a_submitted_non_fillable_column_on_update(): void
    {
        $id = $this->createProduct();
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->patchJson($this->updateUrl('MutationProduct', (string) $id), [
            'internal_note' => 'should not be saved',
        ]);

        $response->assertOk();
        $values = collect($response->json('data.values'))->keyBy('column');

        $this->assertTrue($values['internal_note']['is_null']);
    }

    /**
     * EX-465/EX-466 (Phase 21) : le front ne transmet désormais que les
     * colonnes effectivement modifiées ; ce cas exerce explicitement ce
     * payload partiel (une seule colonne parmi plusieurs, y compris une
     * colonne `unique` absente du payload) — déjà géré tel quel par
     * `ItemRepository::update()` (`fill()` ne touche que les clés fournies),
     * mais jamais exercé par un test avec un payload réellement partiel
     * jusqu'ici (les tests précédents ne fournissaient qu'une colonne parce
     * que le modèle de test n'en a qu'une à faire varier, pas pour vérifier
     * l'absence d'effet de bord sur les autres).
     */
    public function test_it_updates_a_single_column_from_a_partial_payload_without_affecting_the_others(): void
    {
        $id = $this->createProduct(['sku' => 'SKU-PARTIAL', 'price' => 9.99]);
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->patchJson($this->updateUrl('MutationProduct', (string) $id), [
            'price' => 12.5,
        ]);

        $response->assertOk();
        $values = collect($response->json('data.values'))->keyBy('column');

        $this->assertEquals(12.5, $values['price']['value']);
        $this->assertSame('Hammer', $values['name']['value']);
        $this->assertSame('SKU-PARTIAL', $values['sku']['value']);
        $this->assertSame(1, $values['category_id']['value']);
    }

    public function test_it_returns_404_when_updating_an_unknown_item(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->patchJson($this->updateUrl('MutationProduct', '999'), [
            'name' => 'Updated hammer',
        ]);

        $response->assertStatus(404);
    }

    public function test_it_encodes_an_array_value_as_json_for_a_json_column_on_create(): void
    {
        $this->skipUnlessSqliteSupportsNativeJson();

        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->storeUrl('MutationProduct'), [
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

        $response = $this->actingAs($user)->patchJson($this->updateUrl('MutationProduct', (string) $firstId), [
            'sku' => 'SKU-SECOND',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['sku']]);
    }
}
