<?php

namespace Quatrebarbes\Modelbase\Tests\Feature\Livewire;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Factories\UserFactory;
use Quatrebarbes\Modelbase\Http\Middleware\SetLocale;
use Quatrebarbes\Modelbase\Tests\TestCase;

/**
 * Fixture partagée par les tests Feature des composants Livewire (Phases 28
 * à 35) : une connexion `primary` avec deux modèles liés (`LwCategory`
 * hasMany `LwProduct`, `LwProduct` belongsTo `LwCategory`), un produit
 * soft-deleted, une colonne JSON — de quoi exercer listing/détail/édition/
 * relations/suppression sans dupliquer ce montage dans chaque fichier de
 * test (même esprit que les fixtures de `tests/Feature/ItemListingTest.php`
 * etc. pour l'API, réutilisé ici plutôt que dupliqué).
 */
abstract class LivewireTestCase extends TestCase
{
    protected string $sqlitePath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Fichier sur disque plutôt que ':memory:' : EnsureConnectionIsNavigable
        // purge la connexion avant de servir la requête (EX-204/EX-208).
        $this->sqlitePath = tempnam(sys_get_temp_dir(), 'modelbase_lw_test_');

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath,
            'use_native_json' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(app_path('Models'));

        Schema::connection('primary')->create('lw_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::connection('primary')->create('lw_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lw_category_id')->nullable()->constrained('lw_categories');
            $table->string('name');
            $table->decimal('price');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::connection('primary')->table('lw_categories')->insert(['id' => 1, 'name' => 'Tools']);

        DB::connection('primary')->table('lw_products')->insert([
            'id' => 1,
            'lw_category_id' => 1,
            'name' => 'Hammer',
            'price' => 9.99,
            'metadata' => json_encode(['weight_kg' => 1.2]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('primary')->table('lw_products')->insert([
            'id' => 2,
            'lw_category_id' => 1,
            'name' => 'Trashed saw',
            'price' => 4.5,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        $namespace = app()->getNamespace();

        File::put(app_path('Models/LwCategory.php'), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\HasMany;

        class LwCategory extends Model
        {
            protected \$table = 'lw_categories';
            protected \$connection = 'primary';
            protected \$fillable = ['name'];

            public function products(): HasMany
            {
                return \$this->hasMany(LwProduct::class, 'lw_category_id');
            }
        }
        PHP);

        File::put(app_path('Models/LwProduct.php'), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\BelongsTo;
        use Illuminate\Database\Eloquent\SoftDeletes;

        class LwProduct extends Model
        {
            use SoftDeletes;

            protected \$table = 'lw_products';
            protected \$connection = 'primary';
            protected \$fillable = ['lw_category_id', 'name', 'price', 'metadata'];
            protected \$casts = ['metadata' => 'array'];

            public function category(): BelongsTo
            {
                return \$this->belongsTo(LwCategory::class, 'lw_category_id');
            }
        }
        PHP);

        // L'autoloader PSR-4 dynamique de Testbench pour `App\` ne recharge
        // pas nécessairement un fichier déposé après coup (cf. « Collision de
        // classes PHP entre tests » dans docs/roadmap.md) — require_once
        // explicite, même pattern que `putModel()` dans les fixtures API
        // existantes (tests/Feature/ItemListingTest.php etc.).
        require_once app_path('Models/LwCategory.php');
        require_once app_path('Models/LwProduct.php');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Models'));

        if (isset($this->sqlitePath) && file_exists($this->sqlitePath)) {
            unlink($this->sqlitePath);
        }

        parent::tearDown();
    }

    protected function actingAsDemoUser(): static
    {
        // Livewire::test() monte un composant directement, sans passer par
        // le routeur ni ses middlewares — SetLocale ne s'exécute donc jamais
        // pour ce type d'appel, contrairement à un vrai accès HTTP
        // ($this->get(...), qui reste inchangé). Posé explicitement ici pour
        // que tous les tests de composants isolés partent bien de la locale
        // par défaut (EX-115), comme le ferait SetLocale pour une requête
        // sans cookie.
        app()->setLocale(SetLocale::DEFAULT);

        $this->actingAs(UserFactory::new()->create());

        return $this;
    }
}
