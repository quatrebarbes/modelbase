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
}
