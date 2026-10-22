<?php

namespace Quatrebarbes\Modelbase\Livewire\Items;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Quatrebarbes\Modelbase\Livewire\Concerns\DiffsItemAttributes;
use Quatrebarbes\Modelbase\Support\ColumnType;
use Quatrebarbes\Modelbase\Support\ItemDeletionException;
use Quatrebarbes\Modelbase\Support\ItemReindexException;
use Quatrebarbes\Modelbase\Support\ItemRepository;
use Quatrebarbes\Modelbase\Support\ItemValidationException;
use Quatrebarbes\Modelbase\Support\RelationRepository;

/**
 * Module 4 : fiche détail d'un item (EX-405 à EX-411, EX-448 à EX-451,
 * EX-463), bascule vers l'édition en place (EX-412 à EX-417, EX-421 à
 * EX-424, EX-464 à EX-468) et actions de suppression/restauration/reindex
 * (EX-418 à EX-420, plage soft-delete/reindex) — un seul composant plutôt que
 * des pages séparées, comme la page unique `[item].vue` qu'il remplace,
 * héberge aussi les tableaux d'objets liés (EX-425 à EX-431, EX-470 à
 * EX-473) : l'onglet actif est un état local à cette page, pas un composant
 * Livewire séparé, l'état à coordonner (quel onglet est actif) n'a de sens
 * qu'ici.
 */
#[Layout('modelbase::livewire.layout.app')]
class ItemDetail extends Component
{
    use DiffsItemAttributes;

    public string $connection;

    public string $model;

    public string $item;

    public array $itemData = [];

    /** @var array<int, array{name: string, type: string, technical: bool, fillable: bool, long: bool, foreign_key?: array{table: string, model: ?string}}> */
    public array $columns = [];

    public array $relations = [];

    public ?string $activeRelation = null;

    public bool $editing = false;

    public array $formValues = [];

    public array $initialValues = [];

    public array $jsonDrafts = [];

    public array $jsonErrors = [];

    public array $errors = [];

    public ?string $deleteError = null;

    public ?string $forceDeleteError = null;

    public function mount(string $connection, string $model, string $item, ItemRepository $items, RelationRepository $relationsRepo): void
    {
        $this->connection = $connection;
        $this->model = $model;
        $this->item = $item;

        $this->loadItem($items);

        $this->columns = $items->columns($connection, $model);

        // EX-425 : une relation belongsTo est déjà représentée comme colonne
        // de clé étrangère (EX-408) — jamais un tableau distinct ici.
        $this->relations = collect($relationsRepo->forModel($connection, $model))
            ->reject(fn (array $r) => $r['type'] === 'BelongsTo')
            ->values()
            ->all();

        $this->activeRelation = $this->relations[0]['name'] ?? null;
    }

    private function loadItem(ItemRepository $items): void
    {
        $found = $items->find($this->connection, $this->model, $this->item);

        if ($found === null) {
            abort(404, 'Item introuvable.');
        }

        $this->itemData = $found;
    }

    public function selectRelationTab(string $name): void
    {
        $this->activeRelation = $name;
    }

    public function startEdit(ItemRepository $items): void
    {
        $this->formValues = [];
        $this->initialValues = [];
        $this->jsonDrafts = [];
        $this->jsonErrors = [];
        $this->errors = [];

        foreach ($this->itemData['values'] as $value) {
            $column = $value['column'];

            if ($value['type'] === ColumnType::JSON->value) {
                // Bug connu d'`ItemRepository::decorate()` (cf. docs/roadmap.md,
                // Phase 21) : une colonne JSON n'est jamais json_decode()e avant
                // d'être renvoyée — corrigé ici plutôt que reporté, pour que la
                // comparaison de diff (EX-465 à EX-468) comme le brouillon
                // affiché dans le formulaire portent sur la valeur structurelle
                // réelle, jamais sur la chaîne brute doublement encodée.
                $decoded = $this->decodeJsonColumn($value['value']);
                $this->initialValues[$column] = $decoded;
                $this->jsonDrafts[$column] = $decoded === null ? '' : json_encode($decoded, JSON_PRETTY_PRINT);

                continue;
            }

            $this->formValues[$column] = $value['value'];
            $this->initialValues[$column] = $value['value'];

            if ($value['type'] === ColumnType::FOREIGN_KEY->value && ! $value['technical'] && $value['fillable']) {
                $this->loadForeignKeyOptions($items, $column);
            }
        }

        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->errors = [];
    }

    /**
     * Bug connu d'`ItemRepository::decorate()` (docs/roadmap.md, Phase 21) :
     * une colonne JSON est renvoyée telle quelle depuis la base, jamais
     * json_decode()e — appelé ici pour l'affichage en lecture (la vue) comme
     * pour l'édition (startEdit() ci-dessus), plutôt que de dupliquer ce
     * contournement à chaque point d'usage.
     */
    public function decodeJsonColumn(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /** @var array<string, array<int, array{id: mixed, label: string}>> */
    public array $foreignKeyOptions = [];

    private function loadForeignKeyOptions(ItemRepository $items, string $column): void
    {
        $definition = collect($this->columns)->firstWhere('column', $column);
        $relatedModel = $definition['foreign_key']['model'] ?? null;

        if ($relatedModel === null) {
            $this->foreignKeyOptions[$column] = [];

            return;
        }

        // EX-415 : sélecteur d'item existant, sans recherche/typeahead —
        // même limite assumée que l'actuel ItemPicker.vue (per_page fixe).
        $related = $items->paginate($this->connection, $relatedModel, 1, 100);
        $relatedKey = $related['meta']['primary_key'];

        $this->foreignKeyOptions[$column] = collect($related['data'])->map(function (array $row) use ($relatedKey) {
            $preview = collect($row)->except([$relatedKey, 'is_trashed'])->first();

            return [
                'id' => $row[$relatedKey],
                'label' => $preview !== null ? "#{$row[$relatedKey]} — {$preview}" : "#{$row[$relatedKey]}",
            ];
        })->all();
    }

    public function hasChanges(): bool
    {
        foreach ($this->formValues as $column => $value) {
            if (array_key_exists($column, $this->jsonDrafts)) {
                continue;
            }

            if (! $this->valuesEqual($value, $this->initialValues[$column] ?? null)) {
                return true;
            }
        }

        foreach ($this->jsonDrafts as $column => $draft) {
            $decoded = $draft === '' ? null : json_decode($draft, true);

            if (! $this->valuesEqual($decoded, $this->initialValues[$column] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function save(ItemRepository $items): void
    {
        $this->errors = [];
        $this->jsonErrors = [];
        $payload = [];

        foreach ($this->jsonDrafts as $column => $draft) {
            $decoded = null;

            if ($draft !== '') {
                $decoded = json_decode($draft, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->jsonErrors[$column] = true;

                    continue;
                }
            }

            if (! $this->valuesEqual($decoded, $this->initialValues[$column] ?? null)) {
                $payload[$column] = $decoded;
            }
        }

        if ($this->jsonErrors !== []) {
            return;
        }

        foreach ($this->formValues as $column => $value) {
            if (array_key_exists($column, $this->jsonDrafts)) {
                continue;
            }

            if (! $this->valuesEqual($value, $this->initialValues[$column] ?? null)) {
                $payload[$column] = $value;
            }
        }

        if ($payload === []) {
            return;
        }

        try {
            $items->update($this->connection, $this->model, $this->item, $payload);
        } catch (ItemValidationException $exception) {
            $this->errors = collect($exception->errors())->map(fn (array $messages) => $messages[0])->all();

            return;
        }

        $this->loadItem($items);
        $this->editing = false;
        $this->dispatch('toast', message: __('modelbase::itemDetail.saved'), type: 'success');
    }

    public function delete(ItemRepository $items): void
    {
        $this->deleteError = null;

        try {
            $items->delete($this->connection, $this->model, $this->item);
        } catch (ItemDeletionException $exception) {
            $this->deleteError = $exception->getMessage();

            return;
        }

        // Pas de navigate: true : un redirect en page complète garantit que
        // le listing d'items rechargé ne provient jamais du cache client de
        // wire:navigate (cf. commentaire équivalent dans ItemListing/ItemCreate).
        $this->redirect(route('modelbase.web.items.index', [
            'connection' => $this->connection,
            'model' => $this->model,
        ]));
    }

    public function restore(ItemRepository $items): void
    {
        $restored = $items->restore($this->connection, $this->model, $this->item);

        if ($restored === null) {
            $this->dispatch('toast', message: __('modelbase::itemDetail.restoreFailed'), type: 'error');

            return;
        }

        $this->loadItem($items);
        $this->dispatch('toast', message: __('modelbase::itemDetail.restored'), type: 'success');
    }

    public function forceDelete(ItemRepository $items): void
    {
        $this->forceDeleteError = null;

        try {
            $items->forceDelete($this->connection, $this->model, $this->item);
        } catch (ItemDeletionException $exception) {
            $this->forceDeleteError = $exception->getMessage();

            return;
        }

        // Pas de navigate: true : un redirect en page complète garantit que
        // le listing d'items rechargé ne provient jamais du cache client de
        // wire:navigate (cf. commentaire équivalent dans ItemListing/ItemCreate).
        $this->redirect(route('modelbase.web.items.index', [
            'connection' => $this->connection,
            'model' => $this->model,
        ]));
    }

    public function reindex(ItemRepository $items): void
    {
        try {
            $items->reindex($this->connection, $this->model, $this->item);
        } catch (ItemReindexException) {
            $this->dispatch('toast', message: __('modelbase::itemDetail.reindexFailed'), type: 'error');

            return;
        }

        $this->dispatch('toast', message: __('modelbase::itemDetail.reindexed'), type: 'success');
    }

    public function relationUrl(string $relatedConnection, string $relatedModel, mixed $id): string
    {
        return route('modelbase.web.items.show', [
            'connection' => $relatedConnection,
            'model' => $relatedModel,
            'item' => $id,
        ]);
    }

    public function render()
    {
        return view('modelbase::livewire.items.detail');
    }
}
