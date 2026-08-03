<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ColumnIntrospector;
use Quatrebarbes\Modelbase\Support\ColumnType;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ColumnIntrospectorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
            // Sans ce flag, une colonne JSON sqlite est physiquement stockée
            // en 'text' (cf. SQLiteGrammar::typeJson) et indiscernable d'une
            // colonne string à l'introspection — propre à sqlite, mysql/pgsql
            // exposent nativement un type 'json'.
            'use_native_json' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('primary')->create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::connection('primary')->create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories');
            $table->string('name');
            $table->decimal('price');
            $table->boolean('active');
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
        });
    }

    /**
     * @return array<string, array{name: string, type: string, is_foreign_key: bool, foreign_key: array{table: string, column: string}|null}>
     */
    private function describeProducts(): array
    {
        return collect((new ColumnIntrospector)->forTable('primary', 'products'))->keyBy('name')->all();
    }

    public function test_it_maps_scalar_column_types(): void
    {
        $this->skipUnlessSqliteSupportsNativeJson();

        $columns = $this->describeProducts();

        $this->assertSame(ColumnType::STRING->value, $columns['name']['type']);
        $this->assertSame(ColumnType::NUMBER->value, $columns['price']['type']);
        $this->assertSame(ColumnType::BOOLEAN->value, $columns['active']['type']);
        $this->assertSame(ColumnType::JSON->value, $columns['metadata']['type']);
        $this->assertSame(ColumnType::DATE->value, $columns['published_at']['type']);
    }

    public function test_it_detects_a_foreign_key_column_and_overrides_its_type(): void
    {
        $columns = $this->describeProducts();

        $this->assertTrue($columns['category_id']['is_foreign_key']);
        $this->assertSame(ColumnType::FOREIGN_KEY->value, $columns['category_id']['type']);
        $this->assertSame(
            ['table' => 'categories', 'column' => 'id'],
            $columns['category_id']['foreign_key']
        );
    }

    public function test_a_non_foreign_key_column_carries_no_foreign_key_metadata(): void
    {
        $columns = $this->describeProducts();

        $this->assertFalse($columns['name']['is_foreign_key']);
        $this->assertNull($columns['name']['foreign_key']);
    }

    /**
     * Régression : une relation `belongsTo` à clé composite (ex. déclarée via
     * un package type Compoships) fait que getForeignKeyName() renvoie un
     * array plutôt qu'un string — Eloquent n'impose aucun type sur ce
     * paramètre, la relation se construit donc sans erreur. Une telle
     * relation doit être ignorée par relationForeignKeys() (comme les FK de
     * schéma composites via foreignKeyFor(), hors périmètre de ce module)
     * plutôt que planter en l'utilisant comme clé de tableau (`Cannot access
     * offset of type array on array`).
     */
    public function test_it_ignores_a_composite_relation_foreign_key(): void
    {
        $instance = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected $connection = 'primary';

            protected $table = 'products';

            public $timestamps = false;

            public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
            {
                // Un package type Compoships fournit sa propre sous-classe de
                // BelongsTo dont addConstraints() sait gérer un array de
                // colonnes ; on simule cette construction sans erreur ici via
                // Relation::noConstraints(), la vanilla BelongsTo n'ayant pas
                // cette prise en charge (qualifyColumn() plante sur un array).
                return \Illuminate\Database\Eloquent\Relations\Relation::noConstraints(
                    fn () => $this->belongsTo(ColumnIntrospectorTestCategory::class, ['category_id', 'name'], ['id', 'name'])
                );
            }
        };

        $relations = (new ColumnIntrospector)->relationForeignKeys($instance);

        $this->assertSame([], $relations);
    }

    /**
     * Régression : `MorphTo` étend `BelongsTo` en Eloquent, donc `$relation
     * instanceof BelongsTo` matchait aussi une relation polymorphique avant
     * correction. Appelée sur une instance neuve (sans valeur pour la
     * colonne `*_type`), Laravel résout alors `getRelated()` à l'instance
     * elle-même plutôt qu'au modèle réellement visé (connu seulement pour un
     * item précis) — `commentable_id` se serait vue attribuer une clé
     * étrangère auto-référencée absurde (`comments.id`) plutôt qu'aucune. Une
     * relation `morphTo` n'a de toute façon pas de table cible unique, hors
     * périmètre d'EX-423.
     */
    public function test_it_ignores_a_morph_to_relation(): void
    {
        Schema::connection('primary')->create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->string('body');
        });

        $instance = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected $connection = 'primary';

            protected $table = 'comments';

            public $timestamps = false;

            public function commentable(): \Illuminate\Database\Eloquent\Relations\MorphTo
            {
                return $this->morphTo();
            }
        };

        $relations = (new ColumnIntrospector)->relationForeignKeys($instance);

        $this->assertSame([], $relations);
    }

    /**
     * Régression (incident Phase 12, docs/roadmap.md) : le trait `SoftDeletes`
     * ajoute des méthodes publiques sans paramètre absentes de `Model`
     * (`forceDeleteQuietly`/`restoreQuietly`), donc non couvertes par la seule
     * réflexion sur `Model::class` — invoquée à l'aveugle sur une instance
     * réellement récupérée (`find()`, `exists = true`) par
     * `relationForeignKeys()`, `forceDeleteQuietly()` la supprimait
     * réellement (cf. `RelationRepository::paginateRelated()`/
     * `ItemRepository::update()`, les deux call sites réellement exploitables
     * — un appel sur une instance neuve, `new $class`, `exists = false`, reste
     * lui sans effet, `Model::delete()` court-circuitant sur `! $this->exists`).
     * `RelationIntrospectorTest` couvre déjà le même garde-fou côté
     * `RelationIntrospector` ; couvert ici aussi car les deux mécanismes de
     * réflexion sont censés rester alignés (même `RelationMethodGuard`
     * partagé) sans qu'un futur écart entre les deux passe inaperçu.
     */
    public function test_it_never_invokes_soft_deletes_quiet_methods(): void
    {
        DB::connection('primary')->table('categories')->insert(['id' => 1, 'name' => 'Tools']);
        DB::connection('primary')->table('products')->insert([
            'id' => 1, 'category_id' => 1, 'name' => 'Hammer', 'price' => 10, 'active' => true,
        ]);

        $instance = new class extends \Illuminate\Database\Eloquent\Model
        {
            use SoftDeletes;

            protected $connection = 'primary';

            protected $table = 'products';

            public $timestamps = false;
        };
        $instance = $instance::find(1);

        (new ColumnIntrospector)->relationForeignKeys($instance);

        $this->assertTrue(
            DB::connection('primary')->table('products')->where('id', 1)->exists()
        );
    }
}

class ColumnIntrospectorTestCategory extends \Illuminate\Database\Eloquent\Model
{
    protected $connection = 'primary';

    protected $table = 'categories';

    public $timestamps = false;
}
