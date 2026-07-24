<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Factories\UserFactory;

class ItemDeletionTest extends TestCase
{
    private string $sqlitePath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Cf. ItemMutationTest : fichier sur disque plutôt que ':memory:',
        // EnsureConnectionIsNavigable purgeant la connexion avant de servir
        // la requête (EX-204/EX-208) — les routes de mutation passent par le
        // même middleware imbriqué (EnsureModelIsNavigable).
        $this->sqlitePath = tempnam(sys_get_temp_dir(), 'modelbase_test_');

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath,
            // Sans ce flag explicite, sqlite n'applique aucune contrainte de
            // clé étrangère (cf. SQLiteConnector::configureForeignKeyConstraints,
            // qui ne touche au PRAGMA que si la clé est présente dans la
            // config) — nécessaire ici pour vérifier réellement EX-420.
            'foreign_key_constraints' => true,
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
            $table->decimal('price');
            $table->timestamps();
        });

        DB::connection('primary')->table('categories')->insert(['id' => 1, 'name' => 'Tools']);
        DB::connection('primary')->table('categories')->insert(['id' => 2, 'name' => 'Unused']);

        DB::connection('primary')->table('products')->insert([
            'id' => 1,
            'category_id' => 1,
            'name' => 'Hammer',
            'price' => 9.99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

    private function destroyUrl(string $model, string $item): string
    {
        return route('modelbase.api.connections.models.items.destroy', [
            'connection' => 'primary',
            'model' => $model,
            'item' => $item,
        ]);
    }

    /**
     * EX-418 : suppression d'un item existant, sans contrainte bloquante.
     */
    public function test_it_deletes_an_item_not_referenced_by_any_foreign_key(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->deleteJson($this->destroyUrl('Category', '2'));

        $response->assertNoContent();
        $this->assertFalse(DB::connection('primary')->table('categories')->where('id', 2)->exists());
    }

    public function test_it_returns_404_when_deleting_an_unknown_item(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->deleteJson($this->destroyUrl('Category', '999'));

        $response->assertNotFound();
    }

    /**
     * EX-420 : la suppression est bloquée, sans être forcée (cascade), quand
     * l'item est encore référencé par une clé étrangère entrante — l'erreur
     * d'intégrité référentielle de la BDD est renvoyée telle quelle.
     */
    public function test_it_returns_409_when_deletion_is_blocked_by_an_incoming_foreign_key(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->deleteJson($this->destroyUrl('Category', '1'));

        $response->assertStatus(409);
        $response->assertJsonStructure(['message']);
        $this->assertTrue(DB::connection('primary')->table('categories')->where('id', 1)->exists());
    }
}
