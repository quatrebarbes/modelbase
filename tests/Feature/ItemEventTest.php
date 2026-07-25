<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Factories\UserFactory;

/**
 * Phase 4d (EX-424) : les mutations d'items via l'API du plug-in (create/
 * update/delete) déclenchent les événements Eloquent du modèle hôte
 * (creating/created, updating/updated, deleting/deleted) — vérifié ici au
 * niveau HTTP en complément des tests Unit de ItemRepositoryTest, qui
 * couvrent le même comportement au niveau du repository. Rendu possible par
 * la bascule de ItemRepository::create()/update()/delete() du query builder
 * brut (Phase 4b/4c) vers une instance Eloquent réelle (fill()/save()/
 * delete()).
 */
class ItemEventTest extends TestCase
{
    private string $sqlitePath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Cf. ItemMutationTest : fichier sur disque plutôt que ':memory:',
        // EnsureConnectionIsNavigable purgeant la connexion avant de servir
        // la requête (EX-204/EX-208).
        $this->sqlitePath = tempnam(sys_get_temp_dir(), 'modelbase_test_');

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(app_path('Models'));

        Schema::connection('primary')->create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $this->putModel('EventProduct', 'products', ['name']);
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

    private function productClass(): string
    {
        return app()->getNamespace().'Models\\EventProduct';
    }

    private function storeUrl(): string
    {
        return route('modelbase.api.connections.models.items.store', [
            'connection' => 'primary',
            'model' => 'EventProduct',
        ]);
    }

    private function updateUrl(string $item): string
    {
        return route('modelbase.api.connections.models.items.update', [
            'connection' => 'primary',
            'model' => 'EventProduct',
            'item' => $item,
        ]);
    }

    private function destroyUrl(string $item): string
    {
        return route('modelbase.api.connections.models.items.destroy', [
            'connection' => 'primary',
            'model' => 'EventProduct',
            'item' => $item,
        ]);
    }

    public function test_creating_an_item_via_the_api_fires_the_host_models_creating_and_created_events(): void
    {
        $user = UserFactory::new()->create();
        Event::fake();

        $response = $this->actingAs($user)->postJson($this->storeUrl(), ['name' => 'Hammer']);

        $response->assertCreated();
        Event::assertDispatched('eloquent.creating: '.$this->productClass());
        Event::assertDispatched('eloquent.created: '.$this->productClass());
    }

    public function test_updating_an_item_via_the_api_fires_the_host_models_updating_and_updated_events(): void
    {
        $user = UserFactory::new()->create();
        $id = DB::connection('primary')->table('products')->insertGetId(['name' => 'Hammer']);
        Event::fake();

        $response = $this->actingAs($user)->patchJson($this->updateUrl((string) $id), ['name' => 'Updated hammer']);

        $response->assertOk();
        Event::assertDispatched('eloquent.updating: '.$this->productClass());
        Event::assertDispatched('eloquent.updated: '.$this->productClass());
    }

    public function test_deleting_an_item_via_the_api_fires_the_host_models_deleting_and_deleted_events(): void
    {
        $user = UserFactory::new()->create();
        $id = DB::connection('primary')->table('products')->insertGetId(['name' => 'Hammer']);
        Event::fake();

        $response = $this->actingAs($user)->deleteJson($this->destroyUrl((string) $id));

        $response->assertNoContent();
        Event::assertDispatched('eloquent.deleting: '.$this->productClass());
        Event::assertDispatched('eloquent.deleted: '.$this->productClass());
    }
}
