<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ConnectionAvailability;
use Quatrebarbes\Modelbase\Support\EloquentModelFinder;
use Quatrebarbes\Modelbase\Support\ModelResolver;
use Quatrebarbes\Modelbase\Support\RelationIntrospector;
use Quatrebarbes\Modelbase\Support\RelationRepository;
use Quatrebarbes\Modelbase\Support\RelationUnavailableException;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class RelationRepositoryTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        // EX-431 : connexion configurée mais injoignable, pour vérifier la
        // navigabilité d'une relation ciblant un modèle hors de la connexion
        // courante (même fixture que ModelListingTest, EX-206).
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

        DB::connection('primary')->table('categories')->insert(['id' => 1, 'name' => 'Tools']);
        DB::connection('primary')->table('products')->insert([
            ['category_id' => 1, 'name' => 'Hammer'],
            ['category_id' => 1, 'name' => 'Wrench'],
        ]);

        $this->putCategoryWithRelation();
        $this->putProductWithRelations();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Models'));

        parent::tearDown();
    }

    private function namespace(): string
    {
        return app()->getNamespace().'Models';
    }

    private function putModel(string $class, string $table, string $connection): void
    {
        $namespace = $this->namespace();

        File::put(app_path("Models/{$class}.php"), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;

        class {$class} extends Model
        {
            protected \$connection = '{$connection}';

            protected \$table = '{$table}';

            public \$timestamps = false;
        }
        PHP);

        require_once app_path("Models/{$class}.php");
    }

    /**
     * `RelRepoCategory` déclare `products` (hasMany) — utilisé pour vérifier
     * EX-430 (relation sans aucun objet lié) indépendamment de toute
     * relation auto-référencée (cf. `RepoProductWithRelations::siblings()`
     * ci-dessous, qui se retrouverait toujours elle-même).
     */
    private function putCategoryWithRelation(): void
    {
        $namespace = $this->namespace();

        File::put(app_path('Models/RelRepoCategory.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\HasMany;

        class RelRepoCategory extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'categories';

            public \$timestamps = false;

            public function products(): HasMany
            {
                return \$this->hasMany(RepoProductWithRelations::class, 'category_id');
            }
        }
        PHP);

        require_once app_path('Models/RelRepoCategory.php');
    }

    /**
     * `RepoProductWithRelations` déclare : `category` (belongsTo, exclue du
     * listing d'objets liés — EX-425), `siblings` (hasMany, cible la
     * connexion courante) et `unreachableSiblings` (hasMany, cible la
     * connexion `unreachable`, EX-431).
     */
    private function putProductWithRelations(): void
    {
        $namespace = $this->namespace();

        File::put(app_path('Models/RepoProductWithRelations.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\BelongsTo;
        use Illuminate\Database\Eloquent\Relations\HasMany;

        class RepoProductWithRelations extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'products';

            protected \$fillable = ['category_id', 'name'];

            public \$timestamps = false;

            public function category(): BelongsTo
            {
                return \$this->belongsTo(RelRepoCategory::class, 'category_id');
            }

            public function siblings(): HasMany
            {
                return \$this->hasMany(RepoProductWithRelations::class, 'category_id', 'category_id');
            }

            public function unreachableSiblings(): HasMany
            {
                return \$this->hasMany(RepoUnreachableProduct::class, 'category_id', 'category_id');
            }
        }
        PHP);

        require_once app_path('Models/RepoProductWithRelations.php');

        // Modèle non déclaré pour la connexion courante ('primary') : cible
        // d'`unreachableSiblings`, sur la connexion injoignable ci-dessus.
        $this->putModel('RepoUnreachableProduct', 'products', 'unreachable');
    }

    private function repository(): RelationRepository
    {
        $finder = new EloquentModelFinder;
        $resolver = new ModelResolver($finder);

        return new RelationRepository($resolver, new RelationIntrospector, app(ConnectionAvailability::class));
    }

    public function test_for_model_lists_every_declared_relation(): void
    {
        $relations = collect($this->repository()->forModel('primary', 'RepoProductWithRelations'))->keyBy('name');

        $this->assertSame('BelongsTo', $relations['category']['type']);
        $this->assertSame('one', $relations['category']['multiplicity']);
        $this->assertSame('HasMany', $relations['siblings']['type']);
        $this->assertSame('many', $relations['siblings']['multiplicity']);
    }

    public function test_for_model_describes_the_related_model_and_connection(): void
    {
        $relations = collect($this->repository()->forModel('primary', 'RepoProductWithRelations'))->keyBy('name');

        $this->assertSame('RelRepoCategory', $relations['category']['related_model']);
        $this->assertSame('categories', $relations['category']['related_table']);
        $this->assertSame('primary', $relations['category']['related_connection']);
    }

    public function test_a_relation_targeting_the_current_available_connection_is_navigable(): void
    {
        $relations = collect($this->repository()->forModel('primary', 'RepoProductWithRelations'))->keyBy('name');

        $this->assertTrue($relations['siblings']['navigable']);
    }

    /**
     * EX-431 : la relation cible un modèle déclaré sur la connexion
     * `unreachable` (configurée mais injoignable) — signalée non navigable,
     * sans lever d'erreur.
     */
    public function test_a_relation_targeting_an_unavailable_connection_is_not_navigable(): void
    {
        $relations = collect($this->repository()->forModel('primary', 'RepoProductWithRelations'))->keyBy('name');

        $this->assertSame('unreachable', $relations['unreachableSiblings']['related_connection']);
        $this->assertFalse($relations['unreachableSiblings']['navigable']);
    }

    public function test_paginate_related_returns_the_rows_of_a_has_many_relation(): void
    {
        $page = $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '1', 'siblings', 1, 15);

        // 'siblings' inclut l'item lui-même (même category_id) : les 2 produits.
        $this->assertSame(2, $page['meta']['total']);
        $this->assertCount(2, $page['data']);
    }

    /**
     * EX-428 : même besoin que `ItemRepository::paginate()` — la colonne de
     * clé primaire du modèle lié doit être connue du front pour naviguer vers
     * la fiche détail d'une ligne du tableau d'objets liés, même quand elle
     * n'est pas nommée `id`.
     */
    public function test_paginate_related_exposes_the_related_models_primary_key_name(): void
    {
        $page = $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '1', 'siblings', 1, 15);

        $this->assertSame('id', $page['meta']['primary_key']);
    }

    /**
     * EX-430 : relation `products` de `RelRepoCategory` (hasMany, pas
     * auto-référencée contrairement à `siblings` ci-dessus), sur une
     * catégorie qui n'a aucun produit.
     */
    public function test_paginate_related_returns_an_empty_list_without_error_when_the_relation_has_no_rows(): void
    {
        DB::connection('primary')->table('categories')->insert(['id' => 2, 'name' => 'Empty']);

        $page = $this->repository()->paginateRelated('primary', 'RelRepoCategory', '2', 'products', 1, 15);

        $this->assertSame([], $page['data']);
        $this->assertSame(0, $page['meta']['total']);
    }

    public function test_paginate_related_returns_null_for_a_belongs_to_relation(): void
    {
        $page = $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '1', 'category', 1, 15);

        $this->assertNull($page);
    }

    public function test_paginate_related_returns_null_for_an_unknown_relation(): void
    {
        $page = $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '1', 'doesNotExist', 1, 15);

        $this->assertNull($page);
    }

    public function test_paginate_related_returns_null_for_an_unknown_item(): void
    {
        $page = $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '999', 'siblings', 1, 15);

        $this->assertNull($page);
    }

    /**
     * EX-431 : jamais de tentative de requête sur la connexion injoignable —
     * traduite en exception dédiée plutôt qu'en erreur SQL non maîtrisée.
     */
    public function test_paginate_related_throws_for_a_relation_targeting_an_unavailable_connection(): void
    {
        $this->expectException(RelationUnavailableException::class);

        $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '1', 'unreachableSiblings', 1, 15);
    }
}
