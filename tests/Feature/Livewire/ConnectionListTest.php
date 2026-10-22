<?php

namespace Quatrebarbes\Modelbase\Tests\Feature\Livewire;

use Livewire\Livewire;
use Quatrebarbes\Modelbase\Livewire\Connections\ConnectionList;

class ConnectionListTest extends LivewireTestCase
{
    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->get('/modelbase/app')->assertStatus(401);
    }

    public function test_it_lists_configured_connections_sorted_by_name(): void
    {
        $this->actingAsDemoUser();

        Livewire::test(ConnectionList::class)
            ->assertSee('primary')
            ->assertSee('Bases de données');
    }

    public function test_the_status_endpoint_used_by_each_row_is_the_existing_api(): void
    {
        $this->actingAsDemoUser();

        $component = Livewire::test(ConnectionList::class);

        $this->assertStringContainsString(
            '/modelbase/api/connections/primary/status',
            $component->instance()->statusUrl('primary'),
        );
    }

    public function test_the_full_page_route_renders_for_an_authenticated_user(): void
    {
        $this->actingAsDemoUser()
            ->get('/modelbase/app')
            ->assertOk()
            ->assertSee('primary', false);
    }
}
