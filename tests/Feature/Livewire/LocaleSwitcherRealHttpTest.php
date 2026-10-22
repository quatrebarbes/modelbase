<?php

namespace Quatrebarbes\Modelbase\Tests\Feature\Livewire;

/**
 * `LocaleSwitcher::switchTo()` est appelée via une requête AJAX
 * (`/livewire/update`) : `request()` y désigne cette requête elle-même, pas
 * la page affichée par le navigateur — `$this->redirect(request()->fullUrl())`
 * redirigeait donc vers `/livewire/update` lui-même plutôt que de recharger
 * la page (bug signalé par l'utilisateur). `Livewire::test()` ne peut pas
 * détecter ce genre de bug (il n'exécute jamais de vraie requête HTTP), d'où
 * ce test en conditions réelles, comme `ComponentRegistrationTest`.
 */
class LocaleSwitcherRealHttpTest extends LivewireTestCase
{
    public function test_switching_locale_never_redirects_to_the_livewire_update_endpoint(): void
    {
        $this->actingAsDemoUser();

        $page = $this->get('/modelbase/app');
        preg_match_all('/wire:snapshot="(.*?)" wire:effects/s', $page->getContent(), $matches);
        $snapshots = array_map(
            fn (string $raw) => json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5), true),
            $matches[1],
        );
        $switcher = collect($snapshots)->first(fn (array $s) => $s['memo']['name'] === 'modelbase-locale-switcher');
        $this->assertNotNull($switcher);

        $response = $this->postJson('/livewire/update', [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => json_encode($switcher),
                'updates' => new \stdClass(),
                'calls' => [['path' => '', 'method' => 'switchTo', 'params' => ['en']]],
            ]],
        ], ['X-Livewire' => 'true']);

        $response->assertOk();
        $this->assertStringNotContainsString('livewire/update', $response->getContent());
        $this->assertStringContainsString('window.location.reload', $response->getContent());
    }
}
