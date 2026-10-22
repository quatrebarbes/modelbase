<?php

namespace Quatrebarbes\Modelbase\Tests\Feature\Livewire;

/**
 * Chaque composant Livewire du plug-in doit être enregistré explicitement
 * (`Livewire::component()`, cf. `ModelbaseServiceProvider::boot()`) : les
 * classes situées hors du namespace `livewire.class_namespace` de l'app
 * hôte (toujours le cas pour un package tiers) ne peuvent pas être
 * reconstruites par Livewire à partir du seul nom embarqué dans
 * `memo.name` (`ComponentRegistry::generateClassFromName()` préfixe
 * toujours ce namespace, jamais le nôtre). Sans cet enregistrement, le
 * rendu initial d'une page fonctionne (résolution directe de la classe par
 * le routeur), mais toute interaction Livewire suivante (pagination, tri,
 * filtre, bascule du diagramme...) échoue avec une
 * `LivewireReleaseTokenMismatchException` trompeuse (« Page Expired »/419)
 * — Livewire ne trouve pas la classe, ce n'est pas un vrai problème de
 * session/CSRF. Ce test reproduit le scénario réel (chargement de page
 * puis véritable requête HTTP `/livewire/update`, pas `Livewire::test()`
 * qui court-circuite tout ce mécanisme) pour empêcher toute régression.
 */
class ComponentRegistrationTest extends LivewireTestCase
{
    public function test_an_item_listing_interaction_survives_a_real_livewire_update_request(): void
    {
        $this->actingAsDemoUser();

        $page = $this->get('/modelbase/app/connections/primary/models/LwProduct/items');
        $page->assertOk();

        preg_match_all('/wire:snapshot="(.*?)" wire:effects/s', $page->getContent(), $matches);
        $snapshots = array_map(
            fn (string $raw) => json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5), true),
            $matches[1],
        );

        $listingSnapshot = collect($snapshots)->first(fn (array $s) => $s['memo']['name'] === 'modelbase-items-listing');
        $this->assertNotNull($listingSnapshot, 'Le composant ItemListing doit apparaître dans la page rendue.');

        $update = $this->postJson('/livewire/update', [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => json_encode($listingSnapshot),
                'updates' => new \stdClass(),
                'calls' => [['path' => '', 'method' => 'toggleDiagram', 'params' => []]],
            ]],
        ], ['X-Livewire' => 'true']);

        $update->assertOk();
        $this->assertFalse(str_contains($update->getContent(), 'LivewireReleaseTokenMismatchException'));
    }
}
