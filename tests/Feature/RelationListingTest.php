<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Factories\UserFactory;

class RelationListingTest extends TestCase
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
        ]);

        // EX-431 : connexion configurée mais injoignable, ciblée par une
        // relation d'un modèle de `primary` (même fixture qu'ailleurs, EX-206).
        $app['config']->set('database.connections.unreachable', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
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
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('name');
        });

        Schema::connection('primary')->create('blanks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        DB::connection('primary')->table('categories')->insert(['id' => 1, 'name' => 'Tools']);
        DB::connection('primary')->table('products')->insert(['category_id' => 1, 'name' => 'Hammer']);

        $this->putBlank();
        $this->putProduct();
        $this->putCategory();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Models'));

        if (isset($this->sqlitePath) && file_exists($this->sqlitePath)) {
            unlink($this->sqlitePath);
        }

        parent::tearDown();
    }

    private function namespace(): string
    {
        return app()->getNamespace().'Models';
    }

    private function putBlank(): void
    {
        $namespace = $this->namespace();

        File::put(app_path('Models/RelListingBlank.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;

        class RelListingBlank extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'blanks';
        }
        PHP);

        require_once app_path('Models/RelListingBlank.php');
    }

    private function putProduct(): void
    {
        $namespace = $this->namespace();

        File::put(app_path('Models/RelListingProduct.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\BelongsTo;

        class RelListingProduct extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'products';

            protected \$fillable = ['category_id', 'name'];

            public function category(): BelongsTo
            {
                return \$this->belongsTo(RelListingCategory::class, 'category_id');
            }
        }
        PHP);

        require_once app_path('Models/RelListingProduct.php');
    }

    /**
     * EX-431 : `unreachableThing` cible un modèle déclaré sur la connexion
     * `unreachable` (configurée mais injoignable).
     */
    private function putCategory(): void
    {
        $namespace = $this->namespace();

        File::put(app_path('Models/RelListingCategory.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\HasMany;

        class RelListingCategory extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'categories';

            protected \$fillable = ['name'];

            public function products(): HasMany
            {
                return \$this->hasMany(RelListingProduct::class, 'category_id');
            }

            public function unreachableThing(): HasMany
            {
                return \$this->hasMany(RelListingUnreachable::class, 'category_id');
            }
        }
        PHP);

        require_once app_path('Models/RelListingCategory.php');

        File::put(app_path('Models/RelListingUnreachable.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;

        class RelListingUnreachable extends Model
        {
            protected \$connection = 'unreachable';

            protected \$table = 'products';
        }
        PHP);

        require_once app_path('Models/RelListingUnreachable.php');
    }

    private function endpoint(string $model): string
    {
        return route('modelbase.api.connections.models.relations.index', [
            'connection' => 'primary',
            'model' => $model,
        ]);
    }

    public function test_it_lists_the_relations_declared_by_the_model(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('RelListingProduct'));

        $response->assertOk();
        $response->assertJsonFragment([
            'name' => 'category',
            'type' => 'BelongsTo',
            'multiplicity' => 'one',
            'related_model' => 'RelListingCategory',
            'related_connection' => 'primary',
        ]);
    }

    public function test_a_relation_targeting_the_current_connection_is_navigable(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('RelListingCategory'));

        $relations = collect($response->json('data'))->keyBy('name');

        $this->assertTrue($relations['products']['navigable']);
    }

    /**
     * EX-431 : la relation cible un modèle déclaré sur une connexion
     * injoignable — signalée non navigable, sans erreur 500/409 sur ce
     * endpoint (seul le sous-endpoint paginé bloque, cf. ItemRelationsTest).
     */
    public function test_a_relation_targeting_an_unavailable_connection_is_flagged_not_navigable(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('RelListingCategory'));

        $response->assertOk();
        $relations = collect($response->json('data'))->keyBy('name');

        $this->assertFalse($relations['unreachableThing']['navigable']);
    }

    /**
     * Limite SFD (module 3) : aucune relation déclarée renvoie une liste
     * vide, pas une erreur — le front affiche un message dédié plutôt qu'un
     * diagramme vide.
     */
    public function test_it_returns_an_empty_list_without_error_when_the_model_declares_no_relation(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('RelListingBlank'));

        $response->assertOk();
        $response->assertJson(['data' => []]);
    }

    public function test_navigation_is_blocked_for_a_model_not_declared_on_the_connection(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('DoesNotExist'));

        $response->assertStatus(404);
    }
}
