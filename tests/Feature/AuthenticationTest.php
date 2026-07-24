<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Http\Middleware\Authenticate;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Routing\Router;
use Orchestra\Testbench\Factories\UserFactory;

class AuthenticationTest extends TestCase
{
    /**
     * Route de test protégée par le middleware réellement appliqué au groupe
     * `routes/api.php` : le groupe du plug-in est vide tant que les modules
     * 2-4 n'exposent aucun endpoint, cette sonde permet d'exercer le
     * middleware d'auth (EX-101/EX-102/EX-103) dès la Phase 1.
     */
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware(Authenticate::class)
            ->get('/__modelbase-test/probe', fn () => response()->json(['ok' => true]));
    }

    public function test_unauthenticated_request_is_rejected_without_redirect(): void
    {
        $response = $this->getJson('/__modelbase-test/probe');

        $response->assertStatus(401);
        $response->assertHeaderMissing('Location');
    }

    public function test_authenticated_user_can_access(): void
    {
        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->getJson('/__modelbase-test/probe')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_access_is_not_conditioned_by_any_user_specific_right(): void
    {
        // EX-101/EX-102 : aucune restriction basée sur un rôle ou un droit
        // utilisateur — deux utilisateurs quelconques accèdent de façon
        // identique dès lors qu'ils sont authentifiés.
        $userA = UserFactory::new()->create(['name' => 'Alice']);
        $userB = UserFactory::new()->create(['name' => 'Bob']);

        $this->actingAs($userA)->getJson('/__modelbase-test/probe')->assertOk();
        $this->actingAs($userB)->getJson('/__modelbase-test/probe')->assertOk();
    }
}
