<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ColumnIntrospector;
use Quatrebarbes\Modelbase\Support\ConnectionAvailability;
use Quatrebarbes\Modelbase\Support\DatabaseErrorTranslator;
use Quatrebarbes\Modelbase\Support\EloquentModelFinder;
use Quatrebarbes\Modelbase\Support\ItemFilterException;
use Quatrebarbes\Modelbase\Support\ItemQueryFilter;
use Quatrebarbes\Modelbase\Support\ItemRepository;
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
            // Colonne réelle de la table, volontairement absente du
            // $fillable de RepoProductWithRelations (cf. putProductWithRelations())
            // et sans cast déclaré — sert à vérifier EX-427 (même logique
            // d'aperçu qu'EX-402 : un tableau d'objets liés ne renvoie que les
            // colonnes exposées par le modèle lié).
            $table->string('internal_note')->nullable();
        });

        DB::connection('primary')->table('categories')->insert(['id' => 1, 'name' => 'Tools']);
        DB::connection('primary')->table('products')->insert([
            ['category_id' => 1, 'name' => 'Hammer'],
            ['category_id' => 1, 'name' => 'Wrench'],
        ]);

        // EX-472 : table pivot d'une relation belongsToMany, dotée d'un
        // attribut ('note') propre au lien plutôt qu'au modèle lié — utilisée
        // pour vérifier que le filtre/tri d'un tableau d'objets liés ne porte
        // jamais sur les attributs de cette table pivot.
        Schema::connection('primary')->create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::connection('primary')->create('product_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('tag_id');
            $table->string('note')->nullable();
        });

        DB::connection('primary')->table('tags')->insert(['id' => 1, 'name' => 'Sharp']);
        DB::connection('primary')->table('product_tag')->insert(['product_id' => 1, 'tag_id' => 1, 'note' => 'fragile']);

        $this->putCategoryWithRelation();
        $this->putModel('RelRepoTag', 'tags', 'primary', ['name']);
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

    /**
     * @param  array<int, string>  $fillable  EX-422 : seule une colonne
     *   fillable/castée/technique/de relation est exposée par
     *   `ItemRepository::columnsFor()` — nécessaire ici pour que le nom du
     *   modèle lié reste filtrable/triable (EX-472).
     */
    private function putModel(string $class, string $table, string $connection, array $fillable = []): void
    {
        $namespace = $this->namespace();
        $fillableList = implode(', ', array_map(fn (string $column) => "'{$column}'", $fillable));

        File::put(app_path("Models/{$class}.php"), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;

        class {$class} extends Model
        {
            protected \$connection = '{$connection}';

            protected \$table = '{$table}';

            protected \$fillable = [{$fillableList}];

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
        use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

            public function tags(): BelongsToMany
            {
                return \$this->belongsToMany(RelRepoTag::class, 'product_tag', 'product_id', 'tag_id')->withPivot('note');
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
        $items = new ItemRepository($resolver, $finder, new ColumnIntrospector, new DatabaseErrorTranslator, new ItemQueryFilter);

        return new RelationRepository($resolver, new RelationIntrospector, app(ConnectionAvailability::class), $items, new ItemQueryFilter);
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
     * EX-427 : « même logique d'aperçu que le listing standard » (EX-402) —
     * `internal_note`, réelle en base mais absente du $fillable de
     * RepoProductWithRelations (cf. setUp()) et sans cast déclaré, ne doit
     * jamais apparaître dans une ligne d'objet lié.
     */
    public function test_paginate_related_omits_a_column_not_exposed_by_the_related_model(): void
    {
        $page = $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '1', 'siblings', 1, 15);

        $this->assertArrayNotHasKey('internal_note', $page['data'][0]);
        $this->assertArrayHasKey('name', $page['data'][0]);
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

    /**
     * EX-470/EX-433 : filtre "contient" insensible à la casse sur une colonne
     * texte du modèle lié, même comportement que le listing standard.
     */
    public function test_paginate_related_filters_rows_by_column_value(): void
    {
        $page = $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '1', 'siblings', 1, 15, ['name' => 'ham']);

        $this->assertSame(1, $page['meta']['total']);
        $this->assertSame('Hammer', $page['data'][0]['name']);
    }

    /**
     * EX-470/EX-435 : tri sur une colonne du modèle lié, même comportement que
     * le listing standard.
     */
    public function test_paginate_related_sorts_rows_by_column(): void
    {
        $page = $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '1', 'siblings', 1, 15, [], '-name');

        $this->assertSame('Wrench', $page['data'][0]['name']);
        $this->assertSame('Hammer', $page['data'][1]['name']);
    }

    /**
     * EX-472 : une colonne inconnue du modèle lié (ici, un nom qui n'existe
     * ni dans `products` ni dans `categories`) est rejetée plutôt que tentée
     * telle quelle dans une requête SQL.
     */
    public function test_paginate_related_throws_for_a_filter_on_an_unknown_column(): void
    {
        $this->expectException(ItemFilterException::class);

        $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '1', 'siblings', 1, 15, ['does_not_exist' => 'x']);
    }

    /**
     * EX-472 : même garde-fou côté tri.
     */
    public function test_paginate_related_throws_for_a_sort_on_an_unknown_column(): void
    {
        $this->expectException(ItemFilterException::class);

        $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '1', 'siblings', 1, 15, [], 'does_not_exist');
    }

    /**
     * EX-472 : `note` est un attribut de la table pivot `product_tag`, pas du
     * modèle lié (`RelRepoTag`) — exclu du filtre/tri au même titre qu'il
     * n'est pas affiché dans l'aperçu du tableau d'objets liés.
     */
    public function test_paginate_related_rejects_a_filter_on_a_pivot_column_of_a_belongs_to_many_relation(): void
    {
        $this->expectException(ItemFilterException::class);

        $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '1', 'tags', 1, 15, ['note' => 'fragile']);
    }

    /**
     * EX-472 : à l'inverse, une colonne réelle du modèle lié d'une relation
     * belongsToMany reste filtrable/triable normalement.
     */
    public function test_paginate_related_filters_rows_of_a_belongs_to_many_relation_by_a_related_model_column(): void
    {
        $page = $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '1', 'tags', 1, 15, ['name' => 'sharp']);

        $this->assertSame(1, $page['meta']['total']);
    }

    /**
     * EX-473 : aucun filtre/tri n'est tenté lorsque la relation n'est pas
     * navigable — l'exception d'indisponibilité doit être levée avant même
     * qu'un filtre invalide soit évalué (sinon un 422 masquerait le vrai 409).
     */
    public function test_paginate_related_throws_unavailable_before_evaluating_an_invalid_filter(): void
    {
        $this->expectException(RelationUnavailableException::class);

        $this->repository()->paginateRelated('primary', 'RepoProductWithRelations', '1', 'unreachableSiblings', 1, 15, ['does_not_exist' => 'x']);
    }
}
