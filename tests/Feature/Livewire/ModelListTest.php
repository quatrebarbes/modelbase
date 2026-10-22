<?php

namespace Quatrebarbes\Modelbase\Tests\Feature\Livewire;

use Livewire\Livewire;
use Quatrebarbes\Modelbase\Livewire\Models\ModelList;

class ModelListTest extends LivewireTestCase
{
    public function test_it_lists_models_declared_for_the_connection(): void
    {
        $this->actingAsDemoUser();

        Livewire::test(ModelList::class, ['connection' => 'primary'])
            ->assertSee('LwCategory')
            ->assertSee('LwProduct');
    }

    public function test_the_full_page_route_enforces_connection_navigability(): void
    {
        $this->actingAsDemoUser()
            ->get('/modelbase/app/connections/does-not-exist/models')
            ->assertStatus(404);
    }

    public function test_the_full_page_route_renders_for_a_navigable_connection(): void
    {
        $this->actingAsDemoUser()
            ->get('/modelbase/app/connections/primary/models')
            ->assertOk()
            ->assertSee('LwProduct', false);
    }
}
