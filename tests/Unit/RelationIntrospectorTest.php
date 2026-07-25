<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\RelationIntrospector;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class RelationIntrospectorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
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
            $table->unsignedBigInteger('category_id');
            $table->string('name');
        });

        Schema::connection('primary')->create('product_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('notes');
        });

        Schema::connection('primary')->create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::connection('primary')->create('product_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('tag_id');
        });

        Schema::connection('primary')->create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->string('body');
        });

        Schema::connection('primary')->create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::connection('primary')->create('authors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('country_id');
            $table->string('name');
        });

        Schema::connection('primary')->create('posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author_id');
            $table->string('title');
        });

        DB::connection('primary')->table('categories')->insert(['id' => 1, 'name' => 'Tools']);
        DB::connection('primary')->table('products')->insert(['id' => 1, 'category_id' => 1, 'name' => 'Hammer']);
        DB::connection('primary')->table('product_details')->insert(['product_id' => 1, 'notes' => 'Steel head']);
        DB::connection('primary')->table('tags')->insert(['id' => 1, 'name' => 'Sale']);
        DB::connection('primary')->table('product_tag')->insert(['product_id' => 1, 'tag_id' => 1]);
        DB::connection('primary')->table('comments')->insert([
            'commentable_type' => $this->productClass(),
            'commentable_id' => 1,
            'body' => 'Nice hammer',
        ]);
        DB::connection('primary')->table('countries')->insert(['id' => 1, 'name' => 'Wonderland']);
        DB::connection('primary')->table('authors')->insert(['id' => 1, 'country_id' => 1, 'name' => 'Alice']);
        DB::connection('primary')->table('posts')->insert(['author_id' => 1, 'title' => 'Hello']);

        $this->putBareModel('RelProductDetail', 'product_details');
        $this->putBareModel('RelTag', 'tags');
        $this->putBareModel('RelComment', 'comments');
        $this->putBareModel('RelAuthor', 'authors');
        $this->putBareModel('RelPost', 'posts');
        $this->putCategory();
        $this->putProduct();
        $this->putCountry();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Models'));

        parent::tearDown();
    }

    private function hostNamespace(): string
    {
        return app()->getNamespace().'Models';
    }

    private function productClass(): string
    {
        return $this->hostNamespace().'\\RelProduct';
    }

    private function categoryClass(): string
    {
        return $this->hostNamespace().'\\RelCategoryTarget';
    }

    private function countryClass(): string
    {
        return $this->hostNamespace().'\\RelCountry';
    }

    private function product(): mixed
    {
        $class = $this->productClass();

        return $class::find(1);
    }

    private function category(): mixed
    {
        $class = $this->categoryClass();

        return $class::find(1);
    }

    private function country(): mixed
    {
        $class = $this->countryClass();

        return $class::find(1);
    }

    private function putBareModel(string $class, string $table): void
    {
        $namespace = $this->hostNamespace();

        File::put(app_path("Models/{$class}.php"), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;

        class {$class} extends Model
        {
            protected \$connection = 'primary';

            protected \$table = '{$table}';

            public \$timestamps = false;
        }
        PHP);

        require_once app_path("Models/{$class}.php");
    }

    /**
     * EX-307/EX-306 : Category (côté "un") hasMany Product.
     */
    private function putCategory(): void
    {
        $namespace = $this->hostNamespace();

        File::put(app_path('Models/RelCategoryTarget.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\HasMany;

        class RelCategoryTarget extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'categories';

            public \$timestamps = false;

            public function products(): HasMany
            {
                return \$this->hasMany(RelProduct::class, 'category_id');
            }
        }
        PHP);

        require_once app_path('Models/RelCategoryTarget.php');
    }

    /**
     * EX-307 : modèle déclarant un exemplaire de chaque type de relation visé
     * (belongsTo, hasOne, belongsToMany, morphMany, morphOne), plus deux
     * méthodes destinées à vérifier la sécurité de la réflexion réutilisée
     * d'EX-423 : une qui exige un paramètre (jamais invoquée, cf.
     * getNumberOfRequiredParameters()) et une qui lève une exception une fois
     * invoquée (capturée et ignorée, sans faire échouer l'introspection).
     */
    private function putProduct(): void
    {
        $namespace = $this->hostNamespace();

        File::put(app_path('Models/RelProduct.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\BelongsTo;
        use Illuminate\Database\Eloquent\Relations\BelongsToMany;
        use Illuminate\Database\Eloquent\Relations\HasOne;
        use Illuminate\Database\Eloquent\Relations\MorphMany;
        use Illuminate\Database\Eloquent\Relations\MorphOne;

        class RelProduct extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'products';

            protected \$fillable = ['category_id', 'name'];

            public \$timestamps = false;

            public function category(): BelongsTo
            {
                return \$this->belongsTo(RelCategoryTarget::class, 'category_id');
            }

            public function detail(): HasOne
            {
                return \$this->hasOne(RelProductDetail::class, 'product_id');
            }

            public function tags(): BelongsToMany
            {
                return \$this->belongsToMany(RelTag::class, 'product_tag', 'product_id', 'tag_id');
            }

            public function comments(): MorphMany
            {
                return \$this->morphMany(RelComment::class, 'commentable');
            }

            public function featuredComment(): MorphOne
            {
                return \$this->morphOne(RelComment::class, 'commentable');
            }

            public function withRequiredParam(string \$x): BelongsTo
            {
                return \$this->belongsTo(RelCategoryTarget::class, 'category_id');
            }

            public function throwsWhenInvoked(): BelongsTo
            {
                throw new \RuntimeException('never invoked blindly in a correct implementation');
            }
        }
        PHP);

        require_once app_path('Models/RelProduct.php');
    }

    /**
     * EX-307 : Country hasManyThrough Post (via Author) — chaîne à 3 tables.
     */
    private function putCountry(): void
    {
        $namespace = $this->hostNamespace();

        File::put(app_path('Models/RelCountry.php'), <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\HasManyThrough;

        class RelCountry extends Model
        {
            protected \$connection = 'primary';

            protected \$table = 'countries';

            public \$timestamps = false;

            public function posts(): HasManyThrough
            {
                return \$this->hasManyThrough(RelPost::class, RelAuthor::class, 'country_id', 'author_id', 'id', 'id');
            }
        }
        PHP);

        require_once app_path('Models/RelCountry.php');
    }

    public function test_it_detects_a_belongs_to_relation(): void
    {
        $relations = (new RelationIntrospector)->relationsOf($this->product());

        $this->assertSame('BelongsTo', $relations['category']['type']);
        $this->assertSame('one', $relations['category']['multiplicity']);
        $this->assertSame('RelCategoryTarget', class_basename($relations['category']['related']));
    }

    public function test_it_detects_a_has_many_relation(): void
    {
        $relations = (new RelationIntrospector)->relationsOf($this->category());

        $this->assertSame('HasMany', $relations['products']['type']);
        $this->assertSame('many', $relations['products']['multiplicity']);
        $this->assertSame('RelProduct', class_basename($relations['products']['related']));
    }

    public function test_it_detects_a_has_one_relation(): void
    {
        $relations = (new RelationIntrospector)->relationsOf($this->product());

        $this->assertSame('HasOne', $relations['detail']['type']);
        $this->assertSame('one', $relations['detail']['multiplicity']);
    }

    public function test_it_detects_a_belongs_to_many_relation(): void
    {
        $relations = (new RelationIntrospector)->relationsOf($this->product());

        $this->assertSame('BelongsToMany', $relations['tags']['type']);
        $this->assertSame('many', $relations['tags']['multiplicity']);
    }

    public function test_it_detects_a_morph_many_relation(): void
    {
        $relations = (new RelationIntrospector)->relationsOf($this->product());

        $this->assertSame('MorphMany', $relations['comments']['type']);
        $this->assertSame('many', $relations['comments']['multiplicity']);
    }

    public function test_it_detects_a_morph_one_relation(): void
    {
        $relations = (new RelationIntrospector)->relationsOf($this->product());

        $this->assertSame('MorphOne', $relations['featuredComment']['type']);
        $this->assertSame('one', $relations['featuredComment']['multiplicity']);
    }

    public function test_it_detects_a_has_many_through_relation(): void
    {
        $relations = (new RelationIntrospector)->relationsOf($this->country());

        $this->assertSame('HasManyThrough', $relations['posts']['type']);
        $this->assertSame('many', $relations['posts']['multiplicity']);
        $this->assertSame('RelPost', class_basename($relations['posts']['related']));
    }

    public function test_it_skips_a_method_that_requires_a_parameter(): void
    {
        $relations = (new RelationIntrospector)->relationsOf($this->product());

        $this->assertArrayNotHasKey('withRequiredParam', $relations);
    }

    public function test_it_skips_a_method_that_throws_when_invoked(): void
    {
        $relations = (new RelationIntrospector)->relationsOf($this->product());

        $this->assertArrayNotHasKey('throwsWhenInvoked', $relations);
    }

    /**
     * Sécurité de la réflexion (partagée avec ColumnIntrospector::
     * relationForeignKeys, EX-423) : les méthodes publiques sans paramètre
     * requis de Model lui-même (save/delete/push/...) ne doivent jamais être
     * invoquées à l'aveugle — vérifié en confirmant que l'item existe
     * toujours en base après l'introspection (delete() n'a pas été déclenché).
     */
    public function test_it_never_invokes_a_model_builtin_method(): void
    {
        $relations = (new RelationIntrospector)->relationsOf($this->product());

        $this->assertArrayNotHasKey('delete', $relations);
        $this->assertArrayNotHasKey('save', $relations);
        $this->assertArrayNotHasKey('push', $relations);
        $this->assertArrayNotHasKey('fresh', $relations);
        $this->assertArrayNotHasKey('replicate', $relations);
        $this->assertNotNull(DB::connection('primary')->table('products')->find(1));
    }
}
