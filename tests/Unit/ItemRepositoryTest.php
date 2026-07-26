<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ColumnIntrospector;
use Quatrebarbes\Modelbase\Support\DatabaseErrorTranslator;
use Quatrebarbes\Modelbase\Support\EloquentModelFinder;
use Quatrebarbes\Modelbase\Support\ItemFilterException;
use Quatrebarbes\Modelbase\Support\ItemQueryFilter;
use Quatrebarbes\Modelbase\Support\ItemReindexException;
use Quatrebarbes\Modelbase\Support\ItemRepository;
use Quatrebarbes\Modelbase\Support\ItemValidationException;
use Quatrebarbes\Modelbase\Support\ModelResolver;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\ScoutServiceProvider;

class ItemRepositoryTest extends TestCase
{
    /**
     * Phase 13 (EX-444 à EX-447) : le provider Scout n'est enregistré que
     * pour ce test (macro `searchable()` sur les collections Eloquent,
     * config par défaut `scout.*`), plutôt que dans la TestCase de base
     * partagée par toute la suite.
     */
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

        // Driver 'database' : cohérent avec le choix retenu pour l'app de
        // démo (cf. docs/roadmap.md Phase 13) — pas de service externe, et
        // `DatabaseEngine::update()` est un no-op réel (recherche menée en
        // direct sur la table à l'usage), donc sans effet de bord à vérifier
        // ici au-delà de l'absence d'exception.
        $app['config']->set('scout.driver', 'database');
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
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->string('name')->unique();
            $table->string('description')->nullable();
            // Colonne volontairement absente de $fillable (cf. putModel()
            // ci-dessous) pour vérifier EX-421 : une colonne non fillable,
            // hors colonnes techniques (EX-416), est traitée en lecture seule.
            // Déclarée dans $casts (sans effet sur son comportement, un cast
            // 'string' sur une colonne déjà string) uniquement pour rester
            // exposée au sens d'EX-422 (colonnes lues depuis le code hôte) :
            // sans ce cast, columnsFor() l'exclurait purement et simplement
            // du listing/de la fiche détail, empêchant de vérifier EX-421.
            $table->string('internal_note')->nullable();
            // Volontairement sans contrainte FK réelle en base (pas de
            // constrained()), contrairement à category_id ci-dessus : sert à
            // vérifier EX-423 (une relation Eloquent belongsTo déclarée sur
            // le modèle hôte doit être détectée comme clé étrangère même en
            // l'absence de toute contrainte au niveau de la base).
            $table->unsignedBigInteger('secondary_category_id')->nullable();
        });

        DB::connection('primary')->table('categories')->insert(['id' => 1, 'name' => 'Tools']);

        DB::connection('primary')->table('products')->insert([
            ['category_id' => 1, 'name' => 'Hammer', 'description' => null, 'internal_note' => null, 'secondary_category_id' => null],
            ['category_id' => 99, 'name' => 'Orphan', 'description' => '', 'internal_note' => null, 'secondary_category_id' => null],
        ]);

        $this->putModel('RepoCategory', 'categories', ['name']);
        $this->putModel('RepoProduct', 'products', ['category_id', 'name', 'description'], ['internal_note' => 'string']);
        $this->putProductWithRelation();
        // Table 'ghosts' volontairement non créée : modèle déclaré côté hôte
        // dont la table n'existe pas réellement (ex. jamais migrée en prod).
        $this->putModel('RepoGhost', 'ghosts', ['name']);

        // Phase 12 (EX-437 à EX-443) : table et modèle dédiés utilisant
        // SoftDeletes, distincts de 'products'/'RepoProduct' ci-dessus pour ne
        // pas affecter les fixtures déjà en place — un item actif (id 1) et un
        // item déjà soft-deleted (id 2, `deleted_at` renseigné directement en
        // base plutôt que via delete(), pour ne pas dépendre du comportement
        // testé par ailleurs, cf. ItemEventTest/Phase 4d).
        Schema::connection('primary')->create('archivable_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        DB::connection('primary')->table('archivable_products')->insert([
            ['id' => 1, 'name' => 'Active', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
            ['id' => 2, 'name' => 'Trashed', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now()],
        ]);

        $this->putArchivableModel();
        $this->putSearchableModel();
        $this->putSearchableArchivableModel();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Models'));

        parent::tearDown();
    }

    /**
     * @param  array<int, string>  $fillable
     * @param  array<string, string>  $casts
     */
    private function putModel(string $class, string $table, array $fillable, array $casts = []): void
    {
        $namespace = app()->getNamespace();
        $fillableList = collect($fillable)->map(fn (string $column) => "'{$column}'")->implode(', ');
        $castsList = collect($casts)->map(fn (string $cast, string $column) => "'{$column}' => '{$cast}'")->implode(', ');

        File::put(app_path("Models/{$class}.php"), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;

        class {$class} extends Model
        {
            protected \$connection = 'primary';

            protected \$table = '{$table}';

            protected \$fillable = [{$fillableList}];

            protected \$casts = [{$castsList}];
        }
        PHP);

        require_once app_path("Models/{$class}.php");
    }

    /**
     * EX-423 : modèle dédié déclarant une relation `belongsTo` sur
     * `secondary_category_id`, une colonne volontairement sans contrainte FK
     * réelle en base (cf. setUp()) — la classe doit être distincte de
     * `RepoProduct` (nom de classe unique à l'échelle de la suite, cf.
     * docs/roadmap.md Phase 3, « Collision de classes PHP »).
     */
    private function putProductWithRelation(): void
    {
        $namespace = app()->getNamespace();

        File::put(app_path('Models/RepoProductWithRelation.php'), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\BelongsTo;

        class RepoProductWithRelation extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'products';

            protected \$fillable = ['category_id', 'name', 'description', 'secondary_category_id'];

            public function secondaryCategory(): BelongsTo
            {
                return \$this->belongsTo(RepoCategory::class, 'secondary_category_id');
            }
        }
        PHP);

        require_once app_path('Models/RepoProductWithRelation.php');
    }

    private function productClass(): string
    {
        return app()->getNamespace().'Models\\RepoProduct';
    }

    /**
     * EX-437 à EX-443 : modèle de test utilisant SoftDeletes.
     */
    private function putArchivableModel(): void
    {
        $namespace = app()->getNamespace();

        File::put(app_path('Models/RepoArchivableProduct.php'), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\SoftDeletes;

        class RepoArchivableProduct extends Model
        {
            use SoftDeletes;

            protected \$connection = 'primary';

            protected \$table = 'archivable_products';

            protected \$fillable = ['name'];
        }
        PHP);

        require_once app_path('Models/RepoArchivableProduct.php');
    }

    private function archivableClass(): string
    {
        return app()->getNamespace().'Models\\RepoArchivableProduct';
    }

    /**
     * EX-444 à EX-447 : modèle de test utilisant le trait Scout Searchable,
     * distinct de RepoProduct (sans ce trait) pour vérifier EX-447.
     */
    private function putSearchableModel(): void
    {
        $namespace = app()->getNamespace();

        File::put(app_path('Models/RepoSearchableProduct.php'), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;
        use Laravel\Scout\Searchable;

        class RepoSearchableProduct extends Model
        {
            use Searchable;

            protected \$connection = 'primary';

            protected \$table = 'products';

            protected \$fillable = ['category_id', 'name', 'description'];
        }
        PHP);

        require_once app_path('Models/RepoSearchableProduct.php');
    }

    /**
     * Modèle combinant Searchable et SoftDeletes, pour vérifier que
     * reindex() retrouve bien un item déjà soft-deleted via withTrashed()
     * (cohérent avec find(), qui reste accessible sur un tel item).
     */
    private function putSearchableArchivableModel(): void
    {
        $namespace = app()->getNamespace();

        File::put(app_path('Models/RepoSearchableArchivableProduct.php'), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\SoftDeletes;
        use Laravel\Scout\Searchable;

        class RepoSearchableArchivableProduct extends Model
        {
            use Searchable, SoftDeletes;

            protected \$connection = 'primary';

            protected \$table = 'archivable_products';

            protected \$fillable = ['name'];
        }
        PHP);

        require_once app_path('Models/RepoSearchableArchivableProduct.php');
    }

    private function repository(): ItemRepository
    {
        $finder = new EloquentModelFinder;

        return new ItemRepository(new ModelResolver($finder), $finder, new ColumnIntrospector, new DatabaseErrorTranslator, new ItemQueryFilter);
    }

    public function test_it_paginates_items_of_a_model(): void
    {
        $page = $this->repository()->paginate('primary', 'RepoProduct', 1, 1);

        $this->assertCount(1, $page['data']);
        $this->assertSame(2, $page['meta']['total']);
        $this->assertSame(1, $page['meta']['current_page']);
        $this->assertSame(2, $page['meta']['last_page']);
    }

    /**
     * EX-433 : filtre « contient » insensible à la casse pour une colonne de
     * type texte.
     */
    public function test_paginate_filters_items_with_a_case_insensitive_contains_match_on_a_text_column(): void
    {
        $page = $this->repository()->paginate('primary', 'RepoProduct', 1, 15, ['name' => 'HAM']);

        $this->assertCount(1, $page['data']);
        $this->assertSame('Hammer', $page['data'][0]['name']);
    }

    /**
     * EX-433 : égalité stricte pour une colonne d'un type autre que texte
     * (ici une clé étrangère).
     */
    public function test_paginate_filters_items_with_a_strict_match_on_a_non_text_column(): void
    {
        $page = $this->repository()->paginate('primary', 'RepoProduct', 1, 15, ['category_id' => 1]);

        $this->assertCount(1, $page['data']);
        $this->assertSame('Hammer', $page['data'][0]['name']);
    }

    /**
     * EX-434 : plusieurs filtres de colonnes combinés en ET.
     */
    public function test_paginate_combines_multiple_filters_with_and(): void
    {
        $page = $this->repository()->paginate('primary', 'RepoProduct', 1, 15, [
            'category_id' => 1,
            'name' => 'Orphan',
        ]);

        $this->assertSame(0, $page['meta']['total']);
    }

    /**
     * EX-435 : tri sur une seule colonne, direction descendante.
     */
    public function test_paginate_sorts_items_by_a_single_column_descending(): void
    {
        $page = $this->repository()->paginate('primary', 'RepoProduct', 1, 15, [], '-name');

        $this->assertSame(['Orphan', 'Hammer'], collect($page['data'])->pluck('name')->all());
    }

    /**
     * EX-436 : ordre de priorité entre colonnes de tri = ordre de la liste —
     * un troisième produit de même category_id qu'Hammer, mais de nom
     * différent, permet de vérifier que le tri secondaire (name desc) ne
     * s'applique qu'au sein du groupe déjà ordonné par le tri primaire
     * (category_id asc).
     */
    public function test_paginate_sorts_items_by_multiple_columns_in_priority_order(): void
    {
        DB::connection('primary')->table('products')->insert([
            'category_id' => 1, 'name' => 'Anvil', 'description' => null, 'internal_note' => null, 'secondary_category_id' => null,
        ]);

        $page = $this->repository()->paginate('primary', 'RepoProduct', 1, 15, [], 'category_id,-name');

        $this->assertSame(['Hammer', 'Anvil', 'Orphan'], collect($page['data'])->pluck('name')->all());
    }

    /**
     * EX-432 : un nom de colonne de filtre inconnu est rejeté explicitement,
     * jamais tenté tel quel dans une requête SQL.
     */
    public function test_paginate_throws_a_filter_exception_for_an_unknown_filter_column(): void
    {
        $this->expectException(ItemFilterException::class);

        try {
            $this->repository()->paginate('primary', 'RepoProduct', 1, 15, ['does_not_exist' => 'x']);
        } catch (ItemFilterException $exception) {
            $this->assertArrayHasKey('does_not_exist', $exception->errors());

            throw $exception;
        }
    }

    /**
     * EX-432/EX-422 : une colonne réelle de la table mais non exposée par
     * columnsFor() (ici `internal_note` pour `RepoProductWithRelation`, cf.
     * test_columns_omits_a_column_unknown_to_the_host_models_code) est
     * rejetée au même titre qu'une colonne totalement inexistante.
     */
    public function test_paginate_throws_a_filter_exception_for_a_column_not_exposed_by_columns_for(): void
    {
        $this->expectException(ItemFilterException::class);

        $this->repository()->paginate('primary', 'RepoProductWithRelation', 1, 15, ['internal_note' => 'x']);
    }

    /**
     * EX-432 : même garde-fou côté tri.
     */
    public function test_paginate_throws_a_filter_exception_for_an_unknown_sort_column(): void
    {
        $this->expectException(ItemFilterException::class);

        $this->repository()->paginate('primary', 'RepoProduct', 1, 15, [], 'does_not_exist');
    }

    /**
     * Un modèle hôte dont la clé primaire n'est pas `id` (`$primaryKey`)
     * n'expose sinon, dans le dictionnaire brut de colonnes du listing, aucun
     * moyen pour le front de savoir laquelle utiliser pour naviguer vers la
     * fiche détail d'une ligne — `meta.primary_key` comble ce manque.
     */
    public function test_paginate_exposes_the_name_of_a_non_default_primary_key_column(): void
    {
        Schema::connection('primary')->create('tags', function (Blueprint $table) {
            $table->string('wipsos_tag')->primary();
            $table->string('label');
        });

        DB::connection('primary')->table('tags')->insert(['wipsos_tag' => 'promo', 'label' => 'Promo']);

        $this->putTagModel();

        $page = $this->repository()->paginate('primary', 'RepoTag', 1, 15);

        $this->assertSame('wipsos_tag', $page['meta']['primary_key']);
        $this->assertSame('promo', $page['data'][0]['wipsos_tag']);
    }

    public function test_paginate_exposes_the_default_primary_key_name_for_a_model_without_items(): void
    {
        $page = $this->repository()->paginate('primary', 'RepoGhost', 1, 15);

        $this->assertSame('id', $page['meta']['primary_key']);
    }

    private function putTagModel(): void
    {
        $namespace = app()->getNamespace();

        File::put(app_path('Models/RepoTag.php'), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;

        class RepoTag extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'tags';

            protected \$primaryKey = 'wipsos_tag';

            public \$incrementing = false;

            protected \$keyType = 'string';

            protected \$fillable = ['label'];

            public \$timestamps = false;
        }
        PHP);

        require_once app_path('Models/RepoTag.php');
    }

    public function test_it_returns_no_items_without_error_for_an_empty_model(): void
    {
        DB::connection('primary')->table('products')->delete();

        $page = $this->repository()->paginate('primary', 'RepoProduct', 1, 15);

        $this->assertSame([], $page['data']);
        $this->assertSame(0, $page['meta']['total']);
    }

    public function test_it_paginates_without_error_for_a_model_whose_table_does_not_exist(): void
    {
        $page = $this->repository()->paginate('primary', 'RepoGhost', 1, 15);

        $this->assertSame([], $page['data']);
        $this->assertSame(0, $page['meta']['total']);
    }

    public function test_columns_returns_no_columns_for_a_model_whose_table_does_not_exist(): void
    {
        $this->assertSame([], $this->repository()->columns('primary', 'RepoGhost'));
    }

    public function test_find_returns_null_for_a_model_whose_table_does_not_exist(): void
    {
        $this->assertNull($this->repository()->find('primary', 'RepoGhost', '1'));
    }

    public function test_find_returns_null_for_an_unknown_item(): void
    {
        $this->assertNull($this->repository()->find('primary', 'RepoProduct', '404'));
    }

    public function test_find_decorates_a_valid_foreign_key_as_navigable(): void
    {
        $item = $this->repository()->find('primary', 'RepoProduct', '1');

        $values = collect($item['values'])->keyBy('column');

        $this->assertSame('foreign_key', $values['category_id']['type']);
        $this->assertSame([
            'table' => 'categories',
            'model' => 'RepoCategory',
            'navigable' => true,
        ], $values['category_id']['foreign_key']);
    }

    public function test_find_flags_a_broken_foreign_key_as_not_navigable(): void
    {
        $item = $this->repository()->find('primary', 'RepoProduct', '2');

        $values = collect($item['values'])->keyBy('column');

        $this->assertFalse($values['category_id']['foreign_key']['navigable']);
    }

    public function test_find_distinguishes_null_from_empty_string(): void
    {
        $item = $this->repository()->find('primary', 'RepoProduct', '1');
        $values = collect($item['values'])->keyBy('column');

        $this->assertTrue($values['description']['is_null']);
        $this->assertNull($values['description']['value']);

        $item = $this->repository()->find('primary', 'RepoProduct', '2');
        $values = collect($item['values'])->keyBy('column');

        $this->assertFalse($values['description']['is_null']);
        $this->assertSame('', $values['description']['value']);
    }

    public function test_columns_flags_the_primary_key_as_technical_and_describes_foreign_keys(): void
    {
        $columns = collect($this->repository()->columns('primary', 'RepoProduct'))->keyBy('column');

        $this->assertTrue($columns['id']['technical']);
        $this->assertFalse($columns['name']['technical']);
        $this->assertSame('foreign_key', $columns['category_id']['type']);
        $this->assertSame('RepoCategory', $columns['category_id']['foreign_key']['model']);
    }

    /**
     * EX-421 : une colonne absente de $fillable côté modèle hôte (ici
     * `internal_note`, cf. putModel()) est signalée comme non fillable, au
     * même titre qu'une colonne technique pour le rendu en lecture seule.
     */
    public function test_columns_flags_a_non_fillable_column_as_not_fillable(): void
    {
        $columns = collect($this->repository()->columns('primary', 'RepoProduct'))->keyBy('column');

        $this->assertFalse($columns['internal_note']['fillable']);
        $this->assertTrue($columns['name']['fillable']);
    }

    /**
     * EX-423 : `secondary_category_id` n'a aucune contrainte FK réelle en
     * base (cf. setUp()), seulement une relation `belongsTo` déclarée sur
     * `RepoProductWithRelation::secondaryCategory()` (cf.
     * putProductWithRelation()) — doit malgré tout être détectée comme clé
     * étrangère, la relation Eloquent du modèle hôte prévalant désormais sur
     * la seule contrainte de la base.
     */
    public function test_columns_detects_a_foreign_key_from_an_eloquent_relation_without_a_database_constraint(): void
    {
        $columns = collect($this->repository()->columns('primary', 'RepoProductWithRelation'))->keyBy('column');

        $this->assertSame('foreign_key', $columns['secondary_category_id']['type']);
        $this->assertSame('categories', $columns['secondary_category_id']['foreign_key']['table']);
    }

    /**
     * EX-422 : une colonne réelle de la table qui n'est ni fillable, ni
     * castée, ni technique, ni détectée comme clé étrangère disparaît
     * entièrement du schéma exposé — ici `internal_note` pour
     * `RepoProductWithRelation`, dont le $fillable (cf.
     * putProductWithRelation()) ne la couvre pas et qui, contrairement à
     * `RepoProduct` (cf. test_columns_flags_a_non_fillable_column_as_not_fillable
     * ci-dessus), ne déclare aucun cast dessus non plus : elle est donc
     * absente plutôt que simplement en lecture seule.
     */
    public function test_columns_omits_a_column_unknown_to_the_host_models_code(): void
    {
        $columns = collect($this->repository()->columns('primary', 'RepoProductWithRelation'))->keyBy('column');

        $this->assertArrayNotHasKey('internal_note', $columns);
    }

    public function test_create_inserts_a_new_item_with_the_submitted_values(): void
    {
        $item = $this->repository()->create('primary', 'RepoProduct', [
            'category_id' => 1,
            'name' => 'Wrench',
            'description' => 'A tool',
        ]);

        $values = collect($item['values'])->keyBy('column');

        $this->assertSame('Wrench', $values['name']['value']);
        $this->assertSame('A tool', $values['description']['value']);
    }

    public function test_create_ignores_a_submitted_primary_key(): void
    {
        $item = $this->repository()->create('primary', 'RepoProduct', [
            'id' => 999,
            'category_id' => 1,
            'name' => 'Wrench',
        ]);

        $this->assertNotSame(999, $item['id']);
    }

    public function test_create_throws_a_validation_exception_for_a_missing_required_column(): void
    {
        $this->expectException(ItemValidationException::class);

        try {
            $this->repository()->create('primary', 'RepoProduct', ['category_id' => 1]);
        } catch (ItemValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());

            throw $exception;
        }
    }

    public function test_create_throws_a_validation_exception_for_a_duplicate_unique_column(): void
    {
        $this->expectException(ItemValidationException::class);

        try {
            $this->repository()->create('primary', 'RepoProduct', ['category_id' => 1, 'name' => 'Hammer']);
        } catch (ItemValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());

            throw $exception;
        }
    }

    public function test_update_modifies_the_values_of_an_existing_item(): void
    {
        $item = $this->repository()->update('primary', 'RepoProduct', '1', ['description' => 'Updated']);

        $values = collect($item['values'])->keyBy('column');
        $this->assertSame('Updated', $values['description']['value']);
    }

    public function test_update_returns_null_for_an_unknown_item(): void
    {
        $this->assertNull($this->repository()->update('primary', 'RepoProduct', '404', ['description' => 'x']));
    }

    public function test_update_ignores_a_submitted_primary_key(): void
    {
        $item = $this->repository()->update('primary', 'RepoProduct', '1', ['id' => 555, 'description' => 'Updated']);

        $this->assertSame(1, $item['id']);
    }

    /**
     * EX-421 : `internal_note` n'est pas dans $fillable (cf. putModel()) —
     * traitée en lecture seule au même titre qu'une colonne technique.
     */
    public function test_create_ignores_a_submitted_non_fillable_column(): void
    {
        $item = $this->repository()->create('primary', 'RepoProduct', [
            'category_id' => 1,
            'name' => 'Wrench',
            'internal_note' => 'should not be saved',
        ]);

        $values = collect($item['values'])->keyBy('column');
        $this->assertTrue($values['internal_note']['is_null']);
    }

    public function test_update_ignores_a_submitted_non_fillable_column(): void
    {
        $item = $this->repository()->update('primary', 'RepoProduct', '1', ['internal_note' => 'should not be saved']);

        $values = collect($item['values'])->keyBy('column');
        $this->assertTrue($values['internal_note']['is_null']);
    }

    /**
     * EX-107 : create() passe désormais par une instance Eloquent réelle
     * (fill()+save()) plutôt que le query builder brut (Phase 4b), pour
     * déclencher les événements du modèle hôte.
     */
    public function test_create_fires_the_host_models_creating_and_created_events(): void
    {
        Event::fake();

        $this->repository()->create('primary', 'RepoProduct', ['category_id' => 1, 'name' => 'Screwdriver']);

        Event::assertDispatched('eloquent.creating: '.$this->productClass());
        Event::assertDispatched('eloquent.created: '.$this->productClass());
    }

    public function test_update_fires_the_host_models_updating_and_updated_events(): void
    {
        Event::fake();

        $this->repository()->update('primary', 'RepoProduct', '1', ['description' => 'Updated']);

        Event::assertDispatched('eloquent.updating: '.$this->productClass());
        Event::assertDispatched('eloquent.updated: '.$this->productClass());
    }

    public function test_delete_fires_the_host_models_deleting_and_deleted_events(): void
    {
        Event::fake();

        $this->repository()->delete('primary', 'RepoProduct', '1');

        Event::assertDispatched('eloquent.deleting: '.$this->productClass());
        Event::assertDispatched('eloquent.deleted: '.$this->productClass());
    }

    /**
     * EX-437 : le listing standard exclut par défaut les items soft-deleted.
     */
    public function test_paginate_excludes_soft_deleted_items_by_default(): void
    {
        $page = $this->repository()->paginate('primary', 'RepoArchivableProduct', 1, 15);

        $this->assertSame(['Active'], collect($page['data'])->pluck('name')->all());
        $this->assertTrue($page['meta']['soft_deletes']);
    }

    /**
     * EX-438 : `trashed=with` ajoute les items soft-deleted aux items actifs.
     */
    public function test_paginate_includes_soft_deleted_items_when_trashed_is_with(): void
    {
        $page = $this->repository()->paginate('primary', 'RepoArchivableProduct', 1, 15, [], null, 'with');

        $this->assertSame(['Active', 'Trashed'], collect($page['data'])->pluck('name')->all());
    }

    /**
     * EX-438 : `trashed=only` restreint le listing aux items soft-deleted.
     */
    public function test_paginate_returns_only_soft_deleted_items_when_trashed_is_only(): void
    {
        $page = $this->repository()->paginate('primary', 'RepoArchivableProduct', 1, 15, [], null, 'only');

        $this->assertSame(['Trashed'], collect($page['data'])->pluck('name')->all());
    }

    /**
     * EX-439 : indicateur `is_trashed` par ligne du listing.
     */
    public function test_paginate_flags_is_trashed_per_row(): void
    {
        $page = $this->repository()->paginate('primary', 'RepoArchivableProduct', 1, 15, [], null, 'with');
        $rows = collect($page['data'])->keyBy('name');

        $this->assertFalse($rows['Active']['is_trashed']);
        $this->assertTrue($rows['Trashed']['is_trashed']);
    }

    /**
     * EX-443 : ni le filtre `trashed`, ni `meta.soft_deletes` n'ont d'effet
     * pour un modèle n'utilisant pas SoftDeletes.
     */
    public function test_paginate_ignores_the_trashed_param_for_a_model_without_soft_deletes(): void
    {
        $page = $this->repository()->paginate('primary', 'RepoProduct', 1, 15, [], null, 'only');

        $this->assertFalse($page['meta']['soft_deletes']);
        $this->assertSame(2, $page['meta']['total']);
    }

    public function test_find_flags_a_soft_deleted_item_as_trashed(): void
    {
        $item = $this->repository()->find('primary', 'RepoArchivableProduct', '2');

        $this->assertTrue($item['is_trashed']);
    }

    public function test_find_flags_an_active_item_as_not_trashed(): void
    {
        $item = $this->repository()->find('primary', 'RepoArchivableProduct', '1');

        $this->assertFalse($item['is_trashed']);
    }

    /**
     * EX-443 : `is_trashed` vaut toujours `false` pour un modèle sans
     * SoftDeletes.
     */
    public function test_find_always_flags_is_trashed_false_for_a_model_without_soft_deletes(): void
    {
        $item = $this->repository()->find('primary', 'RepoProduct', '1');

        $this->assertFalse($item['is_trashed']);
    }

    /**
     * `soft_deletes` renseigne si le modèle gère SoftDeletes indépendamment du
     * statut de l'item consulté — nécessaire côté front pour adapter le
     * message de confirmation avant une suppression standard (EX-419) qui,
     * pour ce modèle, ne fera qu'un soft-delete plutôt qu'une suppression
     * physique.
     */
    public function test_find_flags_soft_deletes_support_regardless_of_the_items_own_status(): void
    {
        $active = $this->repository()->find('primary', 'RepoArchivableProduct', '1');
        $trashed = $this->repository()->find('primary', 'RepoArchivableProduct', '2');

        $this->assertTrue($active['soft_deletes']);
        $this->assertTrue($trashed['soft_deletes']);
    }

    public function test_find_flags_soft_deletes_as_false_for_a_model_without_soft_deletes(): void
    {
        $item = $this->repository()->find('primary', 'RepoProduct', '1');

        $this->assertFalse($item['soft_deletes']);
    }

    /**
     * EX-416/EX-439 : `deleted_at` est traitée comme une colonne technique en
     * lecture seule, au même titre que la clé primaire et les timestamps.
     */
    public function test_columns_flags_the_deleted_at_column_as_technical(): void
    {
        $columns = collect($this->repository()->columns('primary', 'RepoArchivableProduct'))->keyBy('column');

        $this->assertTrue($columns['deleted_at']['technical']);
    }

    /**
     * EX-440 : restauration d'un item soft-deleted.
     */
    public function test_restore_undoes_the_soft_deletion_of_an_item(): void
    {
        $restored = $this->repository()->restore('primary', 'RepoArchivableProduct', '2');

        $this->assertFalse($restored['is_trashed']);
        $this->assertTrue(
            DB::connection('primary')->table('archivable_products')->where('id', 2)->whereNull('deleted_at')->exists()
        );
    }

    public function test_restore_fires_the_host_models_restoring_and_restored_events(): void
    {
        Event::fake();

        $this->repository()->restore('primary', 'RepoArchivableProduct', '2');

        Event::assertDispatched('eloquent.restoring: '.$this->archivableClass());
        Event::assertDispatched('eloquent.restored: '.$this->archivableClass());
    }

    public function test_restore_returns_null_for_an_unknown_item(): void
    {
        $this->assertNull($this->repository()->restore('primary', 'RepoArchivableProduct', '404'));
    }

    /**
     * EX-443 : l'action de restauration n'est pas applicable à un modèle sans
     * SoftDeletes.
     */
    public function test_restore_returns_null_for_a_model_without_soft_deletes(): void
    {
        $this->assertNull($this->repository()->restore('primary', 'RepoProduct', '1'));
    }

    /**
     * EX-441 : suppression définitive (physique) d'un item soft-deleted.
     */
    public function test_force_delete_permanently_removes_a_soft_deleted_item(): void
    {
        $deleted = $this->repository()->forceDelete('primary', 'RepoArchivableProduct', '2');

        $this->assertTrue($deleted);
        $this->assertFalse(DB::connection('primary')->table('archivable_products')->where('id', 2)->exists());
    }

    /**
     * La suppression définitive n'est pas réservée à un item déjà
     * soft-deleted côté repository (la restriction « proposée uniquement sur
     * un item déjà soft-deleted », EX-441, est une règle d'affichage côté
     * front, cf. docs/roadmap.md Phase 12) : un item encore actif peut aussi
     * être supprimé définitivement.
     */
    public function test_force_delete_can_also_remove_an_item_that_is_not_yet_trashed(): void
    {
        $deleted = $this->repository()->forceDelete('primary', 'RepoArchivableProduct', '1');

        $this->assertTrue($deleted);
    }

    public function test_force_delete_fires_the_host_models_force_deleting_and_force_deleted_events(): void
    {
        Event::fake();

        $this->repository()->forceDelete('primary', 'RepoArchivableProduct', '2');

        Event::assertDispatched('eloquent.forceDeleting: '.$this->archivableClass());
        Event::assertDispatched('eloquent.forceDeleted: '.$this->archivableClass());
    }

    public function test_force_delete_returns_false_for_an_unknown_item(): void
    {
        $this->assertFalse($this->repository()->forceDelete('primary', 'RepoArchivableProduct', '404'));
    }

    /**
     * EX-443 : la suppression définitive n'est pas applicable à un modèle
     * sans SoftDeletes.
     */
    public function test_force_delete_returns_false_for_a_model_without_soft_deletes(): void
    {
        $this->assertFalse($this->repository()->forceDelete('primary', 'RepoProduct', '1'));
    }

    /**
     * EX-444/EX-447 : indicateur exposé par la fiche détail, permettant au
     * front de proposer (ou non) l'action « réindexer ».
     */
    public function test_find_flags_a_model_as_searchable_when_it_uses_the_trait(): void
    {
        $found = $this->repository()->find('primary', 'RepoSearchableProduct', '1');

        $this->assertTrue($found['is_searchable']);
    }

    public function test_find_flags_is_searchable_as_false_for_a_model_without_the_trait(): void
    {
        $found = $this->repository()->find('primary', 'RepoProduct', '1');

        $this->assertFalse($found['is_searchable']);
    }

    /**
     * EX-444/EX-445 : invoque le mécanisme natif Scout (searchable()) du
     * modèle hôte — driver 'database' (cf. defineEnvironment()), dont
     * update() est un no-op réel, donc rien de plus à vérifier ici que
     * l'absence d'exception et la valeur de retour.
     */
    public function test_reindex_updates_the_search_index_for_a_searchable_item(): void
    {
        $this->assertTrue($this->repository()->reindex('primary', 'RepoSearchableProduct', '1'));
    }

    /**
     * EX-447 : l'action n'est pas applicable à un modèle sans Searchable.
     */
    public function test_reindex_returns_null_for_a_model_without_the_searchable_trait(): void
    {
        $this->assertNull($this->repository()->reindex('primary', 'RepoProduct', '1'));
    }

    public function test_reindex_returns_null_for_an_unknown_item(): void
    {
        $this->assertNull($this->repository()->reindex('primary', 'RepoSearchableProduct', '404'));
    }

    /**
     * Cohérent avec find(), qui reste accessible sur un item déjà
     * soft-deleted : reindex() le retrouve via withTrashed() pour un modèle
     * combinant Searchable et SoftDeletes plutôt que de le traiter comme
     * introuvable.
     */
    public function test_reindex_finds_an_already_soft_deleted_item_via_with_trashed(): void
    {
        $this->assertTrue($this->repository()->reindex('primary', 'RepoSearchableArchivableProduct', '2'));
    }

    /**
     * EX-446 : une défaillance du driver Scout du modèle hôte (ici un driver
     * non configuré/inconnu, plutôt que de mocker artificiellement une
     * exception) est traduite en ItemReindexException, à charge du
     * contrôleur de la restituer en échec explicite plutôt qu'une erreur
     * serveur générique.
     */
    public function test_reindex_throws_a_reindex_exception_when_the_scout_driver_fails(): void
    {
        config(['scout.driver' => 'unsupported-driver']);

        $this->expectException(ItemReindexException::class);

        $this->repository()->reindex('primary', 'RepoSearchableProduct', '1');
    }
}
