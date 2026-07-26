<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\ScoutServiceProvider;
use Orchestra\Testbench\Factories\UserFactory;

/**
 * Phase 13 (EX-444 à EX-447) : action « réindexer » sur la fiche détail d'un
 * item, pour un modèle utilisant le trait Scout Searchable.
 */
class ItemReindexTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            ScoutServiceProvider::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        // Driver 'database' : local, sans service externe (Algolia/
        // Meilisearch), cf. docs/roadmap.md Phase 13 — même choix que pour
        // l'app de démo.
        $app['config']->set('scout.driver', 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(app_path('Models'));

        Schema::connection('primary')->create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });

        Schema::connection('primary')->create('plain_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        DB::connection('primary')->table('articles')->insert(['id' => 1, 'title' => 'Hello world']);
        DB::connection('primary')->table('plain_items')->insert(['id' => 1, 'name' => 'Plain']);

        $this->putSearchableModel();
        $this->putPlainModel();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Models'));

        parent::tearDown();
    }

    private function putSearchableModel(): void
    {
        $namespace = app()->getNamespace();

        File::put(app_path('Models/ReindexArticle.php'), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;
        use Laravel\Scout\Searchable;

        class ReindexArticle extends Model
        {
            use Searchable;

            protected \$connection = 'primary';

            protected \$table = 'articles';

            protected \$fillable = ['title'];
        }
        PHP);

        require_once app_path('Models/ReindexArticle.php');
    }

    private function putPlainModel(): void
    {
        $namespace = app()->getNamespace();

        File::put(app_path('Models/ReindexPlainItem.php'), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;

        class ReindexPlainItem extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'plain_items';

            protected \$fillable = ['name'];
        }
        PHP);

        require_once app_path('Models/ReindexPlainItem.php');
    }

    private function showUrl(string $model, string $item): string
    {
        return route('modelbase.api.connections.models.items.show', [
            'connection' => 'primary',
            'model' => $model,
            'item' => $item,
        ]);
    }

    private function reindexUrl(string $model, string $item): string
    {
        return route('modelbase.api.connections.models.items.reindex', [
            'connection' => 'primary',
            'model' => $model,
            'item' => $item,
        ]);
    }

    /**
     * EX-444/EX-447 : l'indicateur exposé par la fiche détail conditionne
     * l'affichage de l'action côté front.
     */
    public function test_show_flags_a_searchable_model_as_searchable(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->showUrl('ReindexArticle', '1'));

        $response->assertJsonPath('data.is_searchable', true);
    }

    public function test_show_flags_a_model_without_searchable_as_not_searchable(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->showUrl('ReindexPlainItem', '1'));

        $response->assertJsonPath('data.is_searchable', false);
    }

    /**
     * EX-444/EX-445/EX-446 : déclenche la mise à jour de l'index Scout et
     * confirme le succès.
     */
    public function test_it_reindexes_a_searchable_item(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->reindexUrl('ReindexArticle', '1'));

        $response->assertOk();
        $response->assertJsonStructure(['message']);
    }

    /**
     * EX-447 : l'action n'est pas proposée pour un modèle sans Searchable.
     */
    public function test_reindex_returns_404_for_a_model_without_searchable(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->reindexUrl('ReindexPlainItem', '1'));

        $response->assertNotFound();
    }

    public function test_reindex_returns_404_for_an_unknown_item(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->postJson($this->reindexUrl('ReindexArticle', '999'));

        $response->assertNotFound();
    }

    /**
     * EX-446 : confirmation d'échec explicite, plutôt qu'une erreur serveur
     * générique, quand le driver Scout du modèle hôte est en défaut — ici un
     * driver non configuré/inconnu, plutôt que de mocker artificiellement une
     * exception.
     */
    public function test_reindex_returns_500_when_the_scout_driver_fails(): void
    {
        $user = UserFactory::new()->create();
        config(['scout.driver' => 'unsupported-driver']);

        $response = $this->actingAs($user)->postJson($this->reindexUrl('ReindexArticle', '1'));

        $response->assertStatus(500);
        $response->assertJsonStructure(['message']);
    }
}
