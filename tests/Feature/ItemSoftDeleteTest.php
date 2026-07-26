<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Factories\UserFactory;

/**
 * Phase 12 (EX-437 à EX-443) : gestion des items soft-deleted — listing
 * filtré, indicateur dédié, restauration, suppression définitive.
 */
class ItemSoftDeleteTest extends TestCase
{
    private string $sqlitePath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Cf. ItemDeletionTest : fichier sur disque plutôt que ':memory:'
        // (EnsureConnectionIsNavigable purge la connexion, EX-204/EX-208),
        // contraintes FK explicitement activées pour vérifier réellement le
        // 409 sur suppression définitive bloquée (même piège qu'EX-420).
        $this->sqlitePath = tempnam(sys_get_temp_dir(), 'modelbase_test_');

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath,
            'foreign_key_constraints' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(app_path('Models'));

        Schema::connection('primary')->create('archivable_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('primary')->create('archivable_item_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archivable_item_id')->constrained('archivable_items');
            $table->string('name');
        });

        Schema::connection('primary')->create('plain_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        DB::connection('primary')->table('archivable_items')->insert([
            ['id' => 1, 'name' => 'Active', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
            ['id' => 2, 'name' => 'Trashed', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now()],
            ['id' => 3, 'name' => 'TrashedButReferenced', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now()],
        ]);

        DB::connection('primary')->table('archivable_item_children')->insert([
            'archivable_item_id' => 3,
            'name' => 'Blocking child',
        ]);

        DB::connection('primary')->table('plain_items')->insert(['id' => 1, 'name' => 'Plain']);

        $this->putModel('ArchivableItem', 'archivable_items', ['name'], softDeletes: true);
        $this->putModel('PlainItem', 'plain_items', ['name']);
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
    private function putModel(string $class, string $table, array $fillable, bool $softDeletes = false): void
    {
        $namespace = app()->getNamespace();
        $fillableList = collect($fillable)->map(fn (string $column) => "'{$column}'")->implode(', ');
        $use = $softDeletes ? "use Illuminate\Database\Eloquent\SoftDeletes;\n" : '';
        $trait = $softDeletes ? '    use SoftDeletes;'."\n\n" : '';

        File::put(app_path("Models/{$class}.php"), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;
        {$use}
        class {$class} extends Model
        {
        {$trait}    protected \$connection = 'primary';

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

    private function restoreUrl(string $model, string $item): string
    {
        return route('modelbase.api.connections.models.items.restore', [
            'connection' => 'primary',
            'model' => $model,
            'item' => $item,
        ]);
    }

    private function forceDestroyUrl(string $model, string $item): string
    {
        return route('modelbase.api.connections.models.items.force-destroy', [
            'connection' => 'primary',
            'model' => $model,
            'item' => $item,
        ]);
    }

    public function test_default_listing_excludes_soft_deleted_items(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->indexUrl('ArchivableItem'));

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.name', 'Active');
        $response->assertJsonPath('meta.soft_deletes', true);
    }

    /**
     * EX-438 : `trashed=with` ajoute les items soft-deleted aux items actifs.
     */
    public function test_trashed_with_includes_soft_deleted_items(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->indexUrl('ArchivableItem', ['trashed' => 'with']));

        $response->assertOk();
        $response->assertJsonPath('meta.total', 3);
    }

    /**
     * EX-438 : `trashed=only` restreint le listing aux items soft-deleted.
     */
    public function test_trashed_only_returns_only_soft_deleted_items(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->indexUrl('ArchivableItem', ['trashed' => 'only']));

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);
        $this->assertSame(
            ['Trashed', 'TrashedButReferenced'],
            collect($response->json('data'))->pluck('name')->sort()->values()->all()
        );
    }

    /**
     * EX-439 : indicateur dédié sur la ligne du listing.
     */
    public function test_listing_flags_a_soft_deleted_row_as_trashed(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->indexUrl('ArchivableItem', ['trashed' => 'with']));

        $rows = collect($response->json('data'))->keyBy('name');

        $this->assertFalse($rows['Active']['is_trashed']);
        $this->assertTrue($rows['Trashed']['is_trashed']);
    }

    /**
     * EX-439 : indicateur dédié sur la fiche détail.
     */
    public function test_detail_flags_a_soft_deleted_item_as_trashed(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->showUrl('ArchivableItem', '2'));

        $response->assertOk();
        $response->assertJsonPath('data.is_trashed', true);
    }

    /**
     * `data.soft_deletes` (support du modèle, indépendant du statut de
     * l'item) permet au front d'adapter le message de confirmation avant une
     * suppression standard (EX-419), qui pour ce modèle ne fera qu'un
     * soft-delete plutôt qu'une suppression physique.
     */
    public function test_detail_flags_soft_deletes_support_for_an_active_item(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->showUrl('ArchivableItem', '1'));

        $response->assertJsonPath('data.soft_deletes', true);
    }

    public function test_detail_flags_soft_deletes_as_false_for_a_model_without_soft_deletes(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->showUrl('PlainItem', '1'));

        $response->assertJsonPath('data.soft_deletes', false);
    }

    /**
     * EX-440 : restauration d'un item soft-deleted.
     */
    public function test_it_restores_a_soft_deleted_item(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->restoreUrl('ArchivableItem', '2'));

        $response->assertOk();
        $response->assertJsonPath('data.is_trashed', false);
        $this->assertTrue(
            DB::connection('primary')->table('archivable_items')->where('id', 2)->whereNull('deleted_at')->exists()
        );
    }

    public function test_restore_returns_404_for_an_unknown_item(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->restoreUrl('ArchivableItem', '999'));

        $response->assertNotFound();
    }

    /**
     * EX-443 : l'action de restauration n'est pas applicable à un modèle sans
     * SoftDeletes.
     */
    public function test_restore_returns_404_for_a_model_without_soft_deletes(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->restoreUrl('PlainItem', '1'));

        $response->assertNotFound();
    }

    /**
     * EX-441 : suppression définitive (physique), distincte de la suppression
     * standard.
     */
    public function test_it_permanently_deletes_a_soft_deleted_item(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->deleteJson($this->forceDestroyUrl('ArchivableItem', '2'));

        $response->assertNoContent();
        $this->assertFalse(DB::connection('primary')->table('archivable_items')->where('id', 2)->exists());
    }

    public function test_force_destroy_returns_404_for_an_unknown_item(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->deleteJson($this->forceDestroyUrl('ArchivableItem', '999'));

        $response->assertNotFound();
    }

    /**
     * EX-443 : la suppression définitive n'est pas applicable à un modèle
     * sans SoftDeletes — la suppression standard (EX-418) y reste physique
     * et immédiate (comportement déjà couvert par ItemDeletionTest, Phase 4c).
     */
    public function test_force_destroy_returns_404_for_a_model_without_soft_deletes(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->deleteJson($this->forceDestroyUrl('PlainItem', '1'));

        $response->assertNotFound();
    }

    /**
     * Même protection qu'EX-420, appliquée à la suppression définitive : une
     * contrainte de clé étrangère entrante bloque, sans jamais forcer.
     */
    public function test_force_destroy_returns_409_when_blocked_by_an_incoming_foreign_key(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->deleteJson($this->forceDestroyUrl('ArchivableItem', '3'));

        $response->assertStatus(409);
        $response->assertJsonStructure(['message']);
        $this->assertTrue(DB::connection('primary')->table('archivable_items')->where('id', 3)->exists());
    }
}
