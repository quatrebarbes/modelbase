<?php

namespace Quatrebarbes\Modelbase\Tests\Feature\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Quatrebarbes\Modelbase\Livewire\Items\ItemCreate;

class ItemCreateTest extends LivewireTestCase
{
    public function test_creating_an_item_submits_all_filled_fields(): void
    {
        $this->actingAsDemoUser();

        Livewire::test(ItemCreate::class, ['connection' => 'primary', 'model' => 'LwCategory'])
            ->set('formValues.name', 'Garden')
            ->call('create')
            ->assertRedirect();

        $this->assertSame(1, DB::connection('primary')->table('lw_categories')->where('name', 'Garden')->count());
    }

    public function test_the_full_page_route_renders(): void
    {
        $this->actingAsDemoUser()
            ->get('/modelbase/app/connections/primary/models/LwCategory/items/create')
            ->assertOk();
    }
}
