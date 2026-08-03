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
        });

        DB::connection('primary')->table('categories')->insert([
            ['id' => 1, 'name' => 'Tools'],
            ['id' => 2, 'name' => 'Empty'],
        ]);

        DB::connection('primary')->table('products')->insert([
            ['category_id' => 1, 'name' => 'Hammer'],
            ['category_id' => 1, 'name' => 'Wrench'],
        ]);

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

    private function putProduct(): void
    {
        $namespace = $this->namespace();

        File::put(app_path('Models/ItemRelProduct.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\BelongsTo;

        class ItemRelProduct extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'products';

            protected \$fillable = ['category_id', 'name'];

            public function category(): BelongsTo
            {
                return \$this->belongsTo(ItemRelCategory::class, 'category_id');
            }
        }
        PHP);

        require_once app_path('Models/ItemRelProduct.php');
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
}
