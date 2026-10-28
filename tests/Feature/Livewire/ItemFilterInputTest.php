<?php

namespace Quatrebarbes\Modelbase\Tests\Feature\Livewire;

use Livewire\Livewire;
use Quatrebarbes\Modelbase\Livewire\Items\ItemListing;
use Quatrebarbes\Modelbase\Livewire\Relations\RelationTable;
use Quatrebarbes\Modelbase\Support\RelationRepository;

/**
 * EX-484 à EX-494 : filtre interprété selon le type de colonne dans le
 * front ; une saisie invalide est ignorée, conservée et signalée.
 */
class ItemFilterInputTest extends LivewireTestCase
{
    /**
     * EX-493 : le filtre invalide est ignoré, les filtres valides restent
     * appliqués, la saisie est conservée telle quelle.
     */
    public function test_an_invalid_filter_is_ignored_while_valid_filters_still_apply(): void
    {
        $this->actingAsDemoUser();

        Livewire::test(ItemListing::class, ['connection' => 'primary', 'model' => 'LwProduct'])
            ->set('trashed', 'with')
            ->set('filters.price', '1 000')
            ->assertSee('Hammer')
            ->assertSee('Trashed saw')
            ->set('filters.name', 'saw')
            ->assertDontSee('Hammer')
            ->assertSee('Trashed saw')
            ->assertSet('filters.price', '1 000');
    }

    /**
     * EX-485 : virgule décimale acceptée, égalité numérique.
     */
    public function test_a_valid_number_filter_applies(): void
    {
        $this->actingAsDemoUser();

        Livewire::test(ItemListing::class, ['connection' => 'primary', 'model' => 'LwProduct'])
            ->set('trashed', 'with')
            ->set('filters.price', '4,50')
            ->assertSee('Trashed saw')
            ->assertDontSee('Hammer');
    }

    /**
     * EX-494 : champ signalé et format attendu affiché tant que la saisie
     * est invalide ; plus aucun signalement une fois valide.
     */
    public function test_an_invalid_filter_is_flagged_with_the_expected_format(): void
    {
        $this->actingAsDemoUser();

        $component = Livewire::test(ItemListing::class, ['connection' => 'primary', 'model' => 'LwProduct'])
            ->set('filters.created_at', '2024-')
            ->assertSeeHtml('data-table__filter-input--invalid')
            ->assertSeeHtml('aria-invalid="true"')
            ->assertSee('Format attendu : AAAA, AAAA-MM, AAAA-MM-JJ, AAAA-MM-JJ HH, AAAA-MM-JJ HH:MM ou AAAA-MM-JJ HH:MM:SS')
            ->assertSee('Hammer');

        $component->set('filters.created_at', now()->format('Y'))
            ->assertDontSeeHtml('data-table__filter-input--invalid')
            ->assertDontSee('Format attendu')
            ->assertSee('Hammer');

        $component->set('filters.created_at', '1999')
            ->assertDontSee('Hammer');
    }

    /**
     * EX-470/EX-493 : même traitement sur un tableau d'objets liés.
     */
    public function test_a_relation_table_ignores_and_flags_an_invalid_filter(): void
    {
        $this->actingAsDemoUser();

        $relation = collect(app(RelationRepository::class)->forModel('primary', 'LwCategory'))
            ->firstWhere('name', 'products');

        Livewire::test(RelationTable::class, [
            'connection' => 'primary',
            'model' => 'LwCategory',
            'item' => '1',
            'relation' => $relation,
            'showTitle' => true,
        ])
            ->set('filters.price', '9.99.9')
            ->assertSee('Hammer')
            ->assertSeeHtml('data-table__filter-input--invalid')
            ->assertSee('Format attendu : nombre')
            ->set('filters.price', '9,99')
            ->assertSee('Hammer')
            ->assertDontSeeHtml('data-table__filter-input--invalid')
            ->set('filters.price', '1')
            ->assertDontSee('Hammer')
            ->assertSet('filters.price', '1');
    }

    /**
     * EX-486 : liste à 3 choix pour une colonne booléenne, aucune saisie
     * libre ; champ texte pour les autres types filtrables.
     */
    public function test_a_boolean_column_is_filtered_through_a_three_choice_list(): void
    {
        app()->setLocale('fr');

        $html = view('modelbase::livewire.partials._filter-cell', [
            'column' => ['column' => 'active', 'type' => 'boolean'],
            'invalidFilters' => [],
            'filterErrorPrefix' => 'filter-error',
        ])->render();

        $this->assertStringContainsString('<select', $html);
        $this->assertStringNotContainsString('<input', $html);
        $this->assertMatchesRegularExpression('/<option value="">\s*Tous\s*<\/option>/', $html);
        $this->assertMatchesRegularExpression('/<option value="1">\s*Oui\s*<\/option>/', $html);
        $this->assertMatchesRegularExpression('/<option value="0">\s*Non\s*<\/option>/', $html);
    }
}
