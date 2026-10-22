<?php

namespace Quatrebarbes\Modelbase\Tests\Feature\Livewire;

use Livewire\Livewire;
use Quatrebarbes\Modelbase\Livewire\Items\ItemListing;

class ItemListingComponentTest extends LivewireTestCase
{
    public function test_it_lists_items_of_the_model(): void
    {
        $this->actingAsDemoUser();

        Livewire::test(ItemListing::class, ['connection' => 'primary', 'model' => 'LwProduct'])
            ->assertSee('Hammer')
            // EX-437 : un item soft-deleted n'apparaît pas par défaut.
            ->assertDontSee('Trashed saw');
    }

    public function test_the_trashed_filter_reveals_soft_deleted_items(): void
    {
        $this->actingAsDemoUser();

        Livewire::test(ItemListing::class, ['connection' => 'primary', 'model' => 'LwProduct'])
            ->set('trashed', 'with')
            ->assertSee('Trashed saw');
    }

    public function test_filtering_by_column_value_narrows_the_listing(): void
    {
        $this->actingAsDemoUser();

        Livewire::test(ItemListing::class, ['connection' => 'primary', 'model' => 'LwProduct'])
            ->set('filters.name', 'Hammer')
            ->assertSee('Hammer')
            ->assertDontSee('Trashed saw');
    }

    public function test_sorting_toggles_through_the_shared_sort_string_encoding(): void
    {
        $this->actingAsDemoUser();

        $component = Livewire::test(ItemListing::class, ['connection' => 'primary', 'model' => 'LwProduct']);

        $component->call('toggleSort', 'name', false);
        $this->assertSame('name', $component->get('sort'));

        $component->call('toggleSort', 'name', false);
        $this->assertSame('-name', $component->get('sort'));

        $component->call('toggleSort', 'name', false);
        $this->assertSame('', $component->get('sort'));
    }

    public function test_the_relation_diagram_panel_opens_and_lists_related_models(): void
    {
        $this->actingAsDemoUser();

        Livewire::test(ItemListing::class, ['connection' => 'primary', 'model' => 'LwCategory'])
            ->assertSet('showDiagram', false)
            ->call('toggleDiagram')
            ->assertSet('showDiagram', true)
            ->assertSee('LwProduct');
    }

    public function test_the_full_page_route_enforces_model_navigability(): void
    {
        $this->actingAsDemoUser()
            ->get('/modelbase/app/connections/primary/models/DoesNotExist/items')
            ->assertStatus(404);
    }

    public function test_the_full_page_route_renders_for_a_navigable_model(): void
    {
        $this->actingAsDemoUser()
            ->get('/modelbase/app/connections/primary/models/LwProduct/items')
            ->assertOk()
            ->assertSee('Hammer', false);
    }
}
