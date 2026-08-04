<?php

namespace Quatrebarbes\Modelbase\Tests\Feature;

use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Factories\UserFactory;

class FrontRoutingTest extends TestCase
{
    private const APP_ROOT = '/modelbase/app';

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(public_path('modelbase/app'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('modelbase/app'));

        parent::tearDown();
    }

    private function publishFakeAssets(): void
    {
        // Simule le résultat de `vendor:publish --tag=modelbase-assets`
        // (EX-106) sans dépendre d'un vrai build Nuxt dans les tests.
        File::put(
            public_path('modelbase/app/index.html'),
            '<html><body id="modelbase-spa">SPA shell</body></html>'
        );
    }

    public function test_unauthenticated_request_is_rejected_without_redirect(): void
    {
        // EX-103 : même comportement que l'API (cf. AuthenticationTest), y
        // compris pour une route front consultée comme une page classique.
        $response = $this->get(self::APP_ROOT);

        $response->assertStatus(401);
        $response->assertHeaderMissing('Location');
    }

    public function test_authenticated_user_receives_the_published_spa_shell(): void
    {
        $this->publishFakeAssets();
        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->get(self::APP_ROOT)
            ->assertOk()
            ->assertSee('modelbase-spa', false);
    }

    public function test_any_sub_path_resolves_to_the_same_spa_shell(): void
    {
        // EX-105 : un seul segment "app" dédié au front, le routage interne
        // (Vue Router) prenant ensuite le relais côté navigateur — Laravel ne
        // doit jamais renvoyer de 404 sur un sous-chemin du SPA.
        $this->publishFakeAssets();
        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->get(self::APP_ROOT.'/connections/mysql/models/Product')
            ->assertOk()
            ->assertSee('modelbase-spa', false);
    }

    public function test_missing_published_assets_produce_a_clear_error(): void
    {
        // Assets jamais publiés (aucun vendor:publish exécuté par l'app
        // hôte) : erreur explicite plutôt qu'une page blanche silencieuse.
        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->get(self::APP_ROOT)
            ->assertStatus(500)
            ->assertSee('vendor:publish', false);
    }

    /**
     * Confort (routes/web.php) : le préfixe nu, sans le segment "app",
     * redirige vers le point d'entrée du SPA plutôt que de renvoyer une 404 —
     * middleware "web" seul (pas Authenticate), donc sans authentification
     * préalable requise pour cette seule redirection.
     */
    public function test_the_bare_prefix_redirects_to_the_spa_entry_point(): void
    {
        $this->get('/modelbase')
            ->assertRedirect(self::APP_ROOT);
    }

    public function test_front_and_api_routes_are_distinguished_by_a_dedicated_segment(): void
    {
        // EX-105 : segments "app" (front) et "api" distincts sous le même
        // préfixe commun (EX-104) — la route front est nommée/résolue
        // indépendamment de l'arborescence "api" (routes/api.php).
        $this->assertSame(
            'modelbase/app/{any?}',
            Route::getRoutes()->getByName('modelbase.web.app')->uri()
        );

        // Le catch-all du front ("app") ne doit jamais capturer le segment
        // "api" : une route inexistante sous ce dernier reste un 404 propre
        // au groupe api.php (cf. AuthenticationTest pour la couverture du
        // middleware d'auth de ce groupe), pas le SPA shell.
        $this->publishFakeAssets();
        $user = UserFactory::new()->create();

        $this->actingAs($user)->get(self::APP_ROOT)->assertOk();
        $this->actingAs($user)->getJson('/modelbase/api/does-not-exist')->assertStatus(404);
    }
}
