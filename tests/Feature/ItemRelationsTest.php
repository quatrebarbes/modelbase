<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Factories\UserFactory;

class ItemRelationsTest extends TestCase
{
    private string $sqlitePath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Cf. ItemListingTest : fichier sur disque plutôt que ':memory:',
        // EnsureConnectionIsNavigable purgeant la connexion avant de servir
        // la requête (EX-204/EX-208).
        $this->sqlitePath = tempnam(sys_get_temp_dir(), 'modelbase_test_');

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath,
        ]);

        // EX-431 : connexion configurée mais injoignable.
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
            // $fillable d'ItemRelProduct ci-dessous et sans cast déclaré —
            // sert à vérifier EX-427 (même logique d'aperçu qu'EX-402) au
            // niveau HTTP.
            $table->string('internal_note')->nullable();
        });

        DB::connection('primary')->table('categories')->insert([
            ['id' => 1, 'name' => 'Tools'],
            ['id' => 2, 'name' => 'Empty'],
        ]);

        DB::connection('primary')->table('products')->insert([
            ['category_id' => 1, 'name' => 'Hammer'],
            ['category_id' => 1, 'name' => 'Wrench'],
        ]);

        // EX-472 : table pivot d'une relation belongsToMany, dotée d'un
        // attribut ('note') propre au lien plutôt qu'au modèle lié.
        Schema::connection('primary')->create('item_rel_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::connection('primary')->create('item_rel_product_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('tag_id');
            $table->string('note')->nullable();
        });

        DB::connection('primary')->table('item_rel_tags')->insert(['id' => 1, 'name' => 'Sharp']);
        DB::connection('primary')->table('item_rel_product_tag')->insert(['product_id' => 1, 'tag_id' => 1, 'note' => 'fragile']);

        $this->putProduct();
        $this->putCategory();
        $this->putTag();
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

    private function putProduct(): void
    {
        $namespace = $this->namespace();

        File::put(app_path('Models/ItemRelProduct.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\BelongsTo;
        use Illuminate\Database\Eloquent\Relations\BelongsToMany;

        class ItemRelProduct extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'products';

            protected \$fillable = ['category_id', 'name'];

            public function category(): BelongsTo
            {
                return \$this->belongsTo(ItemRelCategory::class, 'category_id');
            }

            public function tags(): BelongsToMany
            {
                return \$this->belongsToMany(ItemRelTag::class, 'item_rel_product_tag', 'product_id', 'tag_id')->withPivot('note');
            }
        }
        PHP);

        require_once app_path('Models/ItemRelProduct.php');
    }

    private function putTag(): void
    {
        $namespace = $this->namespace();

        File::put(app_path('Models/ItemRelTag.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;

        class ItemRelTag extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'item_rel_tags';

            protected \$fillable = ['name'];
        }
        PHP);

        require_once app_path('Models/ItemRelTag.php');
    }

    private function putCategory(): void
    {
        $namespace = $this->namespace();

        File::put(app_path('Models/ItemRelCategory.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\HasMany;

        class ItemRelCategory extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'categories';

            protected \$fillable = ['name'];

            public function products(): HasMany
            {
                return \$this->hasMany(ItemRelProduct::class, 'category_id');
            }

            public function unreachableThing(): HasMany
            {
                return \$this->hasMany(ItemRelUnreachable::class, 'category_id');
            }
        }
        PHP);

        require_once app_path('Models/ItemRelCategory.php');

        File::put(app_path('Models/ItemRelUnreachable.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;

        class ItemRelUnreachable extends Model
        {
            protected \$connection = 'unreachable';

            protected \$table = 'products';
        }
        PHP);

        require_once app_path('Models/ItemRelUnreachable.php');
    }

    private function endpoint(string $model, string $item, string $relation, array $query = []): string
    {
        $url = route('modelbase.api.connections.models.items.relations.index', [
            'connection' => 'primary',
            'model' => $model,
            'item' => $item,
            'relation' => $relation,
        ]);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    public function test_it_paginates_the_related_items_of_a_relation(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '1', 'products', ['per_page' => 1]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.total', 2);
    }

    /**
     * EX-454 : même repli sur la première/dernière page qu'ItemRepository::paginate().
     */
    public function test_it_falls_back_to_the_first_or_last_page_when_the_requested_page_is_out_of_bounds(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '1', 'products', ['per_page' => 1, 'page' => 0]));
        $response->assertOk();
        $response->assertJsonPath('meta.current_page', 1);

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '1', 'products', ['per_page' => 1, 'page' => 99]));
        $response->assertOk();
        $response->assertJsonPath('meta.current_page', 2);
    }

    /**
     * EX-427 : « même logique d'aperçu que le listing standard » (EX-402) —
     * `internal_note`, réelle en base mais absente du $fillable d'ItemRelProduct
     * (cf. setUp()) et sans cast déclaré, ne doit jamais apparaître dans une
     * ligne d'objet lié.
     */
    public function test_it_omits_a_column_not_exposed_by_the_related_model_from_a_relation_row(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '1', 'products'));

        $response->assertOk();
        $this->assertArrayNotHasKey('internal_note', $response->json('data.0'));
        $this->assertArrayHasKey('name', $response->json('data.0'));
    }

    /**
     * EX-428 : chaque ligne du tableau porte l'identifiant de l'item lié,
     * permettant au front de construire le lien vers sa propre fiche détail.
     */
    public function test_related_rows_carry_the_related_items_primary_key(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '1', 'products'));

        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Hammer'));
        $this->assertTrue($names->contains('Wrench'));
    }

    /**
     * EX-430 : la catégorie « Empty » (id 2) n'a aucun produit.
     */
    public function test_it_returns_an_empty_list_without_error_when_the_relation_has_no_related_items(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '2', 'products'));

        $response->assertOk();
        $response->assertJson(['data' => []]);
    }

    public function test_it_returns_404_for_an_unknown_relation(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '1', 'doesNotExist'));

        $response->assertStatus(404);
    }

    /**
     * EX-425 : `belongsTo` n'est pas exposée comme un tableau d'objets liés
     * (déjà couverte par la valeur de colonne de clé étrangère, EX-408).
     */
    public function test_it_returns_404_for_a_belongs_to_relation(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelProduct', '1', 'category'));

        $response->assertStatus(404);
    }

    public function test_it_returns_404_for_an_unknown_item(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '999', 'products'));

        $response->assertStatus(404);
    }

    /**
     * EX-431 : la relation cible un modèle déclaré sur une connexion
     * configurée mais injoignable — 409, jamais de suppression forcée ni de
     * requête tentée sur cette connexion.
     */
    public function test_it_returns_409_when_the_targeted_connection_is_unavailable(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '1', 'unreachableThing'));

        $response->assertStatus(409);
    }

    /**
     * EX-470/EX-433 : filtre "contient" insensible à la casse sur une colonne
     * texte du modèle lié, même comportement que le listing standard (EX-432
     * à EX-434).
     */
    public function test_it_filters_related_rows_with_a_case_insensitive_contains_match_on_a_text_column(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '1', 'products', ['filter' => ['name' => 'HAM']]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Hammer');
    }

    /**
     * EX-434 : plusieurs filtres de colonnes combinés en ET.
     */
    public function test_it_combines_multiple_filters_on_related_rows_with_and(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '1', 'products', [
            'filter' => ['category_id' => 1, 'name' => 'Orphan'],
        ]));

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    /**
     * EX-435/EX-436 : tri simple et multi-colonnes avec priorité sur le
     * modèle lié.
     */
    public function test_it_sorts_related_rows_by_multiple_columns_in_priority_order(): void
    {
        $user = UserFactory::new()->create();

        DB::connection('primary')->table('products')->insert(['category_id' => 1, 'name' => 'Hammer']);

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '1', 'products', ['sort' => 'name,-id']));

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'Hammer');
        $response->assertJsonPath('data.2.name', 'Wrench');
        // Parmi les deux "Hammer" (tri primaire), le plus récemment inséré
        // (id le plus haut) apparaît en premier (tri secondaire -id).
        $this->assertGreaterThan($response->json('data.1.id'), $response->json('data.0.id'));
    }

    /**
     * EX-472 : colonne inconnue du modèle lié — rejetée en 422, jamais
     * tentée telle quelle dans une requête SQL, même contrat d'erreur
     * qu'ItemController::index() (EX-432).
     */
    public function test_it_rejects_a_filter_on_an_unknown_related_column_with_a_422(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '1', 'products', ['filter' => ['does_not_exist' => 'x']]));

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['does_not_exist']]);
    }

    public function test_it_rejects_a_sort_on_an_unknown_related_column_with_a_422(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '1', 'products', ['sort' => 'does_not_exist']));

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['does_not_exist']]);
    }

    /**
     * EX-472 : `note` est un attribut de la table pivot `item_rel_product_tag`
     * de la relation belongsToMany `tags`, pas du modèle lié (`ItemRelTag`) —
     * exclu du filtre au même titre qu'il n'est pas affiché dans l'aperçu.
     */
    public function test_it_rejects_a_filter_on_a_pivot_column_of_a_belongs_to_many_relation_with_a_422(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelProduct', '1', 'tags', ['filter' => ['note' => 'fragile']]));

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['note']]);
    }

    /**
     * EX-472 : à l'inverse, une colonne réelle du modèle lié reste filtrable.
     */
    public function test_it_filters_a_belongs_to_many_relation_by_a_related_model_column(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelProduct', '1', 'tags', ['filter' => ['name' => 'sharp']]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    /**
     * EX-473 : aucun filtre/tri n'est tenté lorsque la connexion cible de la
     * relation est indisponible — même un filtre par ailleurs invalide ne
     * doit jamais faire passer la réponse de 409 à 422.
     */
    public function test_it_returns_409_rather_than_422_for_an_invalid_filter_on_an_unavailable_connection(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->getJson($this->endpoint('ItemRelCategory', '1', 'unreachableThing', ['filter' => ['does_not_exist' => 'x']]));

        $response->assertStatus(409);
    }
}
