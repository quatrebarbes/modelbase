<?php

namespace Quatrebarbes\Modelbase\Tests\Feature\Livewire;

use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;
use Orchestra\Testbench\Factories\UserFactory;
use Quatrebarbes\Modelbase\Http\Middleware\SetLocale;
use Quatrebarbes\Modelbase\Livewire\Connections\ConnectionList;
use Quatrebarbes\Modelbase\Livewire\Shared\LocaleSwitcher;
use Quatrebarbes\Modelbase\Tests\TestCase;

/**
 * Valide de bout en bout l'authentification, le layout, la feuille de style
 * inlinée et l'i18n du front Livewire (routes/web.php).
 */
class LivewireFrontSkeletonTest extends TestCase
{
    private const APP_ROOT = '/modelbase/app';

    private const FR_TITLE = 'Bases de données';

    private const EN_TITLE = 'Databases';

    public function test_unauthenticated_request_is_rejected_without_redirect(): void
    {
        // EX-103 : même comportement que l'API et l'ancien front Nuxt.
        $response = $this->get(self::APP_ROOT);

        $response->assertStatus(401);
        $response->assertHeaderMissing('Location');
    }

    public function test_authenticated_user_receives_the_layout_with_inlined_stylesheet(): void
    {
        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->get(self::APP_ROOT)
            ->assertOk()
            // EX-106 (supprimée) : la feuille de style est inlinée depuis le
            // package, jamais publiée/servie comme fichier statique séparé.
            ->assertSee('--color-primary: #1c9a97', false)
            ->assertSee(self::FR_TITLE, false);
    }

    public function test_the_layout_inlines_the_diagram_script_without_any_published_asset(): void
    {
        // EX-120/EX-121 : le script du diagramme est inliné depuis le
        // package ; aucune URL ne pointe vers un asset publié dans public/.
        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->get(self::APP_ROOT)
            ->assertOk()
            ->assertSee("Alpine.data('modelbaseRelationDiagram'", false)
            ->assertSee('https://cdnjs.cloudflare.com/ajax/libs/cytoscape/', false)
            ->assertDontSee('diagram-assets', false);
    }

    public function test_default_locale_is_french_regardless_of_browser_language(): void
    {
        // EX-115 : le français est retenu par défaut sans jamais lire
        // Accept-Language, y compris quand le navigateur préfère l'anglais.
        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->withHeaders(['Accept-Language' => 'en'])
            ->get(self::APP_ROOT)
            ->assertOk()
            ->assertSee(self::FR_TITLE, false)
            ->assertDontSee(self::EN_TITLE, false);
    }

    public function test_locale_cookie_overrides_the_default(): void
    {
        // EX-116 : la locale choisie s'applique dès la requête suivante.
        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->withCookie(SetLocale::COOKIE, 'en')
            ->get(self::APP_ROOT)
            ->assertOk()
            ->assertSee(self::EN_TITLE, false);
    }

    public function test_an_invalid_locale_cookie_falls_back_to_french(): void
    {
        $user = UserFactory::new()->create();

        $this->actingAs($user)
            ->withCookie(SetLocale::COOKIE, 'de')
            ->get(self::APP_ROOT)
            ->assertOk()
            ->assertSee(self::FR_TITLE, false);
    }

    public function test_switching_locale_queues_a_long_lived_cookie_and_redirects(): void
    {
        // EX-117 : persistance façon localStorage — cookie "forever", pas la
        // session de l'app hôte (durée de vie bornée).
        $user = UserFactory::new()->create();
        $this->actingAs($user);

        // Pas d'assertRedirect() : switchTo() déclenche un rechargement JS
        // (`$this->js('window.location.reload()')`), pas un redirect Livewire
        // — cf. commentaire de LocaleSwitcher::switchTo() pour le bug que ce
        // choix corrige (`request()->fullUrl()` désignerait la requête AJAX
        // elle-même, pas la page affichée).
        Livewire::test(LocaleSwitcher::class)
            ->call('switchTo', 'en');

        $this->assertTrue(Cookie::hasQueued(SetLocale::COOKIE));
        $queued = Cookie::queued(SetLocale::COOKIE);
        $this->assertSame('en', $queued->getValue());
        $this->assertGreaterThan(now()->addYears(1)->getTimestamp(), $queued->getExpiresTime());
    }

    public function test_an_unsupported_locale_is_ignored_by_the_switcher(): void
    {
        $user = UserFactory::new()->create();
        $this->actingAs($user);

        Livewire::test(LocaleSwitcher::class)->call('switchTo', 'de');

        $this->assertSame(SetLocale::DEFAULT, Cookie::queued(SetLocale::COOKIE)->getValue());
    }

    public function test_a_full_page_component_renders_within_the_livewire_layout(): void
    {
        // Livewire::test() monte le composant directement, sans passer par
        // le routeur ni ses middlewares (SetLocale n'est donc jamais
        // exécuté ici, contrairement à un vrai accès HTTP) — la locale par
        // défaut de l'app (EX-115) est donc posée explicitement, comme le
        // ferait SetLocale pour une requête sans cookie.
        app()->setLocale(SetLocale::DEFAULT);

        $user = UserFactory::new()->create();
        $this->actingAs($user);

        Livewire::test(ConnectionList::class)
            ->assertSee(self::FR_TITLE);
    }
}
