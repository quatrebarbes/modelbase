<?php

namespace Quatrebarbes\Modelbase\Tests\Feature\Livewire;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Quatrebarbes\Modelbase\Livewire\Items\ItemCreate;
use Quatrebarbes\Modelbase\Livewire\Items\ItemDetail;
use Quatrebarbes\Modelbase\Livewire\Items\ItemListing;
use Quatrebarbes\Modelbase\Livewire\Models\ModelList;

/**
 * EX-477 à EX-483 : masquage des colonnes `$hidden`/`$visible` dans le front
 * Livewire. `LwAccount` déclare `secret` (fillable, donc éditable sans
 * masquage) et `token` (ni fillable ni casté) dans `$hidden` — de quoi
 * vérifier qu'aucune vue ne restitue leur valeur et que la fiche cite
 * pourtant leur nom.
 */
class HiddenColumnsTest extends LivewireTestCase
{
    private const SECRET = 'hash-must-never-leak';

    private const TOKEN = 'token-must-never-leak';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('primary')->create('lw_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('token')->nullable();
        });

        DB::connection('primary')->table('lw_accounts')->insert([
            'id' => 1,
            'name' => 'Alice',
            'secret' => self::SECRET,
            'token' => self::TOKEN,
        ]);

        $namespace = app()->getNamespace();

        File::put(app_path('Models/LwAccount.php'), <<<PHP
        <?php

        namespace {$namespace}Models;

        use Illuminate\Database\Eloquent\Model;

        class LwAccount extends Model
        {
            protected \$table = 'lw_accounts';
            protected \$connection = 'primary';
            public \$timestamps = false;
            protected \$fillable = ['name', 'secret'];
            protected \$hidden = ['secret', 'token'];
        }
        PHP);

        require_once app_path('Models/LwAccount.php');
    }

    private function mountDetail(string $model, string $item)
    {
        $this->actingAsDemoUser();

        return Livewire::test(ItemDetail::class, [
            'connection' => 'primary',
            'model' => $model,
            'item' => $item,
        ]);
    }

    /**
     * EX-478/EX-481/EX-482 : la fiche affiche les colonnes non masquées, cite
     * le nom des colonnes masquées, sans jamais restituer leur valeur.
     */
    public function test_the_detail_lists_hidden_column_names_without_their_values(): void
    {
        $component = $this->mountDetail('LwAccount', '1');

        $component->assertSee('Alice')
            ->assertSee(__('modelbase::itemDetail.hiddenColumns'))
            ->assertSet('hiddenColumns', ['secret', 'token'])
            ->assertDontSee(self::SECRET)
            ->assertDontSee(self::TOKEN);

        $this->assertNotContains('secret', array_column($component->get('itemData')['values'], 'column'));
    }

    /**
     * EX-483 : aucune mention pour un modèle sans colonne masquée.
     */
    public function test_the_detail_shows_no_hidden_column_notice_for_a_model_without_any(): void
    {
        $this->mountDetail('LwProduct', '1')
            ->assertSet('hiddenColumns', [])
            ->assertDontSee(__('modelbase::itemDetail.hiddenColumns'));
    }

    /**
     * EX-481 : une colonne masquée mais fillable n'est pas proposée à la
     * modification, et une valeur soumise de force est ignorée à l'écriture.
     */
    public function test_editing_neither_offers_nor_writes_a_hidden_column(): void
    {
        $component = $this->mountDetail('LwAccount', '1');

        $component->call('startEdit');
        $this->assertArrayNotHasKey('secret', $component->get('formValues'));

        $component->set('formValues.name', 'Alice B.')
            ->set('formValues.secret', 'overwritten')
            ->call('save');

        $row = DB::connection('primary')->table('lw_accounts')->find(1);
        $this->assertSame('Alice B.', $row->name);
        $this->assertSame(self::SECRET, $row->secret);
    }

    /**
     * EX-481 : le formulaire de création ne propose pas la colonne masquée.
     */
    public function test_the_create_form_does_not_offer_a_hidden_column(): void
    {
        $this->actingAsDemoUser();

        $component = Livewire::test(ItemCreate::class, ['connection' => 'primary', 'model' => 'LwAccount']);

        $this->assertNotContains('secret', array_column($component->get('columns'), 'name'));
        $this->assertArrayNotHasKey('secret', $component->get('formValues'));
    }

    /**
     * EX-478/EX-481 : le listing ne contient ni la colonne ni sa valeur, et
     * un filtre fourni sur cette colonne (ex. via l'URL) est rejeté comme
     * celui d'une colonne inconnue, sans servir de critère.
     */
    public function test_the_listing_neither_shows_nor_filters_on_a_hidden_column(): void
    {
        $this->actingAsDemoUser();

        $component = Livewire::test(ItemListing::class, ['connection' => 'primary', 'model' => 'LwAccount']);

        $component->assertSee('Alice')->assertDontSee(self::SECRET);
        $this->assertNotContains('secret', array_column($component->get('columns'), 'name'));

        $component->set('filters', ['secret' => 'hash'])
            ->assertSet('filters', [])
            ->assertDontSee(self::SECRET);
    }

    /**
     * EX-302 (précisée) : le nombre de propriétés ne compte pas les colonnes
     * masquées — `id` et `name` seulement pour `LwAccount`.
     */
    public function test_the_model_property_count_excludes_hidden_columns(): void
    {
        $this->actingAsDemoUser();

        $models = collect(Livewire::test(ModelList::class, ['connection' => 'primary'])->get('models'))->keyBy('name');

        $this->assertSame(2, $models['LwAccount']['property_count']);
    }
}
