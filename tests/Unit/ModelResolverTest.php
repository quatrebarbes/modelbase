<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\EloquentModelFinder;
use Quatrebarbes\Modelbase\Support\ModelResolver;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Support\Facades\File;

class ModelResolverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'primary');
        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $app['config']->set('database.connections.secondary', [
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

    private function putModel(string $class, string $connection): void
    {
        $namespace = app()->getNamespace();

        File::put(app_path("Models/{$class}.php"), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;

        class {$class} extends Model
        {
            protected \$connection = '{$connection}';
        }
        PHP);

        require_once app_path("Models/{$class}.php");
    }

    private function resolver(): ModelResolver
    {
        return new ModelResolver(new EloquentModelFinder);
    }

    public function test_it_resolves_a_model_name_to_its_class_for_the_given_connection(): void
    {
        $this->putModel('ResolverWidget', 'primary');

        $class = $this->resolver()->resolve('primary', 'ResolverWidget');

        $this->assertSame(app()->getNamespace().'Models\\ResolverWidget', $class);
    }

    public function test_it_returns_null_for_a_model_name_unknown_on_any_connection(): void
    {
        $this->assertNull($this->resolver()->resolve('primary', 'DoesNotExist'));
    }

    /**
     * Le nom résolu est le `class_basename`, comparé tel quel — une classe
     * déclarée sur une autre connexion que celle demandée n'est pas trouvée
     * même si son nom correspond.
     */
    public function test_it_returns_null_when_the_model_is_declared_on_a_different_connection(): void
    {
        $this->putModel('ResolverGizmo', 'secondary');

        $this->assertNull($this->resolver()->resolve('primary', 'ResolverGizmo'));
    }

    public function test_it_is_case_sensitive_on_the_model_name(): void
    {
        $this->putModel('ResolverSprocket', 'primary');

        $this->assertNull($this->resolver()->resolve('primary', 'resolversprocket'));
    }
}
