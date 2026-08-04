<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\EloquentModelFinder;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Support\Facades\File;

class EloquentModelFinderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'primary');
        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(app_path('Models'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Models'));

        parent::tearDown();
    }

    private function putModel(string $namespace, string $class, string $body = ''): void
    {
        File::put(app_path("Models/{$class}.php"), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;

        class {$class} extends Model
        {
            {$body}
        }
        PHP);

        require app_path("Models/{$class}.php");
    }

    public function test_it_finds_concrete_eloquent_models_declared_in_app_models(): void
    {
        $namespace = app()->getNamespace();
        $this->putModel($namespace, 'Widget');

        $models = (new EloquentModelFinder)->all();

        $this->assertContains($namespace.'Models\\Widget', $models);
    }

    public function test_it_ignores_classes_that_are_not_eloquent_models(): void
    {
        $namespace = app()->getNamespace();

        File::put(app_path('Models/NotAModel.php'), <<<PHP
        <?php

        namespace {$namespace}Models;

        class NotAModel
        {
        }
        PHP);
        require app_path('Models/NotAModel.php');

        $models = (new EloquentModelFinder)->all();

        $this->assertNotContains($namespace.'Models\\NotAModel', $models);
    }

    public function test_it_ignores_abstract_model_base_classes(): void
    {
        $namespace = app()->getNamespace();

        File::put(app_path('Models/AbstractBase.php'), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;

        abstract class AbstractBase extends Model
        {
        }
        PHP);
        require app_path('Models/AbstractBase.php');

        $models = (new EloquentModelFinder)->all();

        $this->assertNotContains($namespace.'Models\\AbstractBase', $models);
    }

    public function test_it_filters_models_by_connection(): void
    {
        $namespace = app()->getNamespace();
        $this->putModel($namespace, 'OnMysql', "protected \$connection = 'mysql';");
        $this->putModel($namespace, 'OnDefault');

        $finder = new EloquentModelFinder;

        $this->assertSame([$namespace.'Models\\OnMysql'], $finder->forConnection('mysql'));
        $this->assertSame([$namespace.'Models\\OnDefault'], $finder->forConnection('primary'));
    }

    public function test_it_returns_no_models_when_app_models_directory_is_absent(): void
    {
        File::deleteDirectory(app_path('Models'));

        $this->assertSame([], (new EloquentModelFinder)->all());
    }

    public function test_it_caches_the_discovered_models_when_ttl_is_positive(): void
    {
        config(['modelbase.model_discovery_cache_ttl' => 60]);
        $namespace = app()->getNamespace();
        $finder = new EloquentModelFinder;

        $this->putModel($namespace, 'Cached');
        $before = $finder->all();
        $this->assertContains($namespace.'Models\\Cached', $before);

        $this->putModel($namespace, 'AddedAfterCaching');
        $after = $finder->all();

        $this->assertNotContains($namespace.'Models\\AddedAfterCaching', $after);
    }

    public function test_it_bypasses_the_cache_when_ttl_is_zero(): void
    {
        config(['modelbase.model_discovery_cache_ttl' => 0]);
        $namespace = app()->getNamespace();
        $finder = new EloquentModelFinder;

        $this->putModel($namespace, 'First');
        $finder->all();

        $this->putModel($namespace, 'Second');
        $after = $finder->all();

        $this->assertContains($namespace.'Models\\First', $after);
        $this->assertContains($namespace.'Models\\Second', $after);
    }

    public function test_class_for_table_finds_the_model_declared_for_a_table_on_the_given_connection(): void
    {
        $namespace = app()->getNamespace();
        $this->putModel($namespace, 'ClassForTableWidget');

        $class = (new EloquentModelFinder)->classForTable('primary', 'class_for_table_widgets');

        $this->assertSame($namespace.'Models\\ClassForTableWidget', $class);
    }

    public function test_class_for_table_returns_null_for_a_table_with_no_declared_model(): void
    {
        $this->assertNull((new EloquentModelFinder)->classForTable('primary', 'does_not_exist'));
    }

    public function test_class_for_table_ignores_models_declared_on_a_different_connection(): void
    {
        $namespace = app()->getNamespace();
        $this->putModel($namespace, 'ClassForTableOnMysql', "protected \$connection = 'mysql';");

        $this->assertNull((new EloquentModelFinder)->classForTable('primary', 'class_for_table_on_mysqls'));
    }

    /**
     * Plusieurs classes Eloquent distinctes pointant vers la même table (cf.
     * ModelListingTest::test_multiple_models_on_the_same_table_are_listed_as_distinct_entries) :
     * classForTable() n'a pas vocation à désambiguïser, seulement à en
     * renvoyer une (la première rencontrée) plutôt que d'échouer.
     */
    public function test_class_for_table_returns_one_candidate_when_several_models_share_the_same_table(): void
    {
        $namespace = app()->getNamespace();
        $this->putModel($namespace, 'SharedTableWidget');
        $this->putModel($namespace, 'SharedTableWidgetAlias', "protected \$table = 'shared_table_widgets';");

        $class = (new EloquentModelFinder)->classForTable('primary', 'shared_table_widgets');

        $this->assertContains($class, [$namespace.'Models\\SharedTableWidget', $namespace.'Models\\SharedTableWidgetAlias']);
    }
}
