<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Factories\UserFactory;

/**
 * EX-104/EX-105 : le front (Livewire, routes/web.php) et l'API
 * (routes/api.php) restent servis sous un préfixe commun et configurable,
 * distingués par un segment dédié — remplace l'ancienne version de ce test
 * (Phase 6 à 37), qui vérifiait le shell SPA Nuxt publié via `vendor:publish`
 * (EX-106, supprimée) ; le comportement d'authentification/i18n/layout du
 * nouveau front Livewire est couvert par
 * tests/Feature/Livewire/LivewireFrontSkeletonTest.php.
 */
class FrontRoutingTest extends TestCase
{
    public function test_the_front_entry_point_lives_under_the_configured_prefix(): void
    {
        $this->assertSame(
            'modelbase/app',
            Route::getRoutes()->getByName('modelbase.web.connections.index')->uri()
        );
    }

    public function test_front_and_api_routes_are_distinguished_by_a_dedicated_segment(): void
    {
        $user = UserFactory::new()->create();

        $this->actingAs($user)->get('/modelbase/app')->assertOk();

        // Le front ("app") ne doit jamais capturer le segment "api" : une
        // route inexistante sous ce dernier reste un 404 propre au groupe
        // api.php (cf. AuthenticationTest pour la couverture du middleware
        // d'auth de ce groupe), jamais une page du front.
        $this->actingAs($user)->getJson('/modelbase/api/does-not-exist')->assertStatus(404);
    }

    public function test_the_bare_prefix_redirects_to_the_front_entry_point(): void
    {
        $this->get('/modelbase')
            ->assertRedirect('/modelbase/app');
    }
}
