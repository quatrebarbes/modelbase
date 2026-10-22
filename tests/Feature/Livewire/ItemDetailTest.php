<?php

namespace Quatrebarbes\Modelbase\Tests\Feature\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Quatrebarbes\Modelbase\Livewire\Items\ItemDetail;

class ItemDetailTest extends LivewireTestCase
{
    private function mountDetail(string $model, string $item)
    {
        $this->actingAsDemoUser();

        return Livewire::test(ItemDetail::class, [
            'connection' => 'primary',
            'model' => $model,
            'item' => $item,
        ]);
    }

    public function test_it_displays_the_item_values(): void
    {
        $this->mountDetail('LwProduct', '1')
            ->assertSee('Hammer')
            ->assertSee('9.99');
    }

    public function test_a_trashed_item_shows_restore_and_force_delete_instead_of_edit_and_delete(): void
    {
        $component = $this->mountDetail('LwProduct', '2');

        $component->assertSee(__('modelbase::itemDetail.restore'))
            ->assertSee(__('modelbase::itemDetail.forceDelete'))
            ->assertDontSee(__('modelbase::itemDetail.edit'));
    }

    public function test_editing_only_submits_changed_columns(): void
    {
        $component = $this->mountDetail('LwProduct', '1');

        $component->call('startEdit')
            ->assertSet('formValues.name', 'Hammer')
            ->set('formValues.name', 'Big Hammer')
            ->call('save');

        $this->assertSame('Big Hammer', DB::connection('primary')->table('lw_products')->find(1)->name);
        $this->assertSame(9.99, (float) DB::connection('primary')->table('lw_products')->find(1)->price);
    }

    public function test_saving_without_any_change_does_nothing(): void
    {
        $this->mountDetail('LwProduct', '1')
            ->call('startEdit')
            ->call('save');

        $this->assertSame('Hammer', DB::connection('primary')->table('lw_products')->find(1)->name);
    }

    /**
     * Bug signalé par l'utilisateur : le bouton Enregistrer ne s'activait
     * jamais, même après une saisie. Cause : les champs du formulaire
     * utilisaient `wire:model` (synchronisation différée au prochain
     * aller-retour réseau) plutôt que `wire:model.live` (synchronisation
     * immédiate) — `ItemDetail::hasChanges()`, recalculée par PHP à chaque
     * rendu, ne se réévaluait donc jamais après une frappe puisqu'aucune
     * requête n'était déclenchée. `Livewire::test()->set()` simule
     * directement la réception d'une mise à jour côté serveur et ne peut
     * donc pas détecter ce bug à lui seul (le serveur réagit correctement
     * dès qu'il reçoit la valeur, quel que soit le modificateur côté
     * client) — ce test vérifie explicitement le rendu HTML des champs.
     */
    public function test_editable_fields_use_a_live_binding_so_the_save_button_reacts_to_typing(): void
    {
        $component = $this->mountDetail('LwProduct', '1');
        $component->call('startEdit');

        $html = $component->html();
        $this->assertStringContainsString('wire:model.live', $html);
        $this->assertDoesNotMatchRegularExpression('/wire:model="formValues/', $html);

        preg_match('/<button type="submit"[^>]*>/', $html, $before);
        $this->assertStringContainsString('disabled', $before[0]);

        $component->set('formValues.name', 'Changed Name');

        preg_match('/<button type="submit"[^>]*>/', $component->html(), $after);
        $this->assertStringNotContainsString('disabled', $after[0]);
    }

    /**
     * `ItemRepository::decorate()`/`find()` ne json_decode() jamais une
     * colonne JSON (bug connu, cf. docs/roadmap.md Phase 21) — corrigé côté
     * Livewire (`ItemDetail::decodeJsonColumn()`) plutôt que reporté : la
     * valeur affichée doit être du JSON réellement indenté, pas une chaîne
     * doublement encodée, et le brouillon d'édition doit rester
     * structurellement identique à l'original tant qu'il n'est pas modifié.
     */
    public function test_a_json_column_is_decoded_before_display_and_editing(): void
    {
        $this->mountDetail('LwProduct', '1')
            ->assertSee('"weight_kg": 1.2')
            ->call('startEdit')
            ->assertSet('jsonDrafts.metadata', json_encode(['weight_kg' => 1.2], JSON_PRETTY_PRINT))
            ->call('save');

        // Non modifié : la colonne JSON ne doit pas être resoumise.
        $this->assertSame(
            '{"weight_kg":1.2}',
            DB::connection('primary')->table('lw_products')->find(1)->metadata
        );
    }

    public function test_delete_removes_a_non_soft_deleting_item_and_redirects(): void
    {
        $this->mountDetail('LwCategory', '1')
            ->call('delete')
            ->assertRedirect();

        $this->assertNull(DB::connection('primary')->table('lw_categories')->find(1));
    }

    public function test_restore_brings_back_a_trashed_item(): void
    {
        $this->mountDetail('LwProduct', '2')
            ->call('restore')
            ->assertDispatched('toast');

        $this->assertNull(DB::connection('primary')->table('lw_products')->find(2)->deleted_at);
    }

    public function test_the_relations_tab_lists_the_category_products(): void
    {
        $this->mountDetail('LwCategory', '1')
            ->assertSee('products');
    }

    public function test_the_full_page_route_404s_for_an_unknown_item(): void
    {
        $this->actingAsDemoUser()
            ->get('/modelbase/app/connections/primary/models/LwProduct/items/999')
            ->assertStatus(404);
    }

    public function test_the_full_page_route_renders_for_a_known_item(): void
    {
        $this->actingAsDemoUser()
            ->get('/modelbase/app/connections/primary/models/LwProduct/items/1')
            ->assertOk()
            ->assertSee('Hammer', false);
    }
}
