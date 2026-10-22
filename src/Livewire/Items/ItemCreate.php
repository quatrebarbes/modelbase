<?php

namespace Quatrebarbes\Modelbase\Livewire\Items;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Quatrebarbes\Modelbase\Support\ColumnType;
use Quatrebarbes\Modelbase\Support\ItemRepository;
use Quatrebarbes\Modelbase\Support\ItemValidationException;

/**
 * Module 4 (EX-412 à EX-417, EX-421 à EX-424) : création d'un item. Même
 * rendu de formulaire par type que l'édition (`ItemDetail::startEdit()`,
 * partiel `_field.blade.php` partagé), mais **sans diff** — toutes les
 * valeurs renseignées sont soumises (EX-465 à EX-468 ne concernent que la
 * modification d'un item existant).
 */
#[Layout('modelbase::livewire.layout.app')]
class ItemCreate extends Component
{
    public string $connection;

    public string $model;

    /** @var array<int, array{name: string, type: string, technical: bool, fillable: bool, long: bool, foreign_key?: array{table: string, model: ?string}}> */
    public array $columns = [];

    public array $formValues = [];

    public array $jsonDrafts = [];

    public array $jsonErrors = [];

    public array $errors = [];

    /** @var array<string, array<int, array{id: mixed, label: string}>> */
    public array $foreignKeyOptions = [];

    public function mount(string $connection, string $model, ItemRepository $items): void
    {
        $this->connection = $connection;
        $this->model = $model;
        $this->columns = $items->columns($connection, $model);

        foreach ($this->columns as $column) {
            if ($column['type'] === ColumnType::JSON->value) {
                $this->jsonDrafts[$column['column']] = '';
            }

            if ($column['type'] === ColumnType::FOREIGN_KEY->value && ! $column['technical'] && $column['fillable']) {
                $this->loadForeignKeyOptions($items, $column);
            }
        }
    }

    private function loadForeignKeyOptions(ItemRepository $items, array $column): void
    {
        $relatedModel = $column['foreign_key']['model'] ?? null;

        if ($relatedModel === null) {
            $this->foreignKeyOptions[$column['column']] = [];

            return;
        }

        $related = $items->paginate($this->connection, $relatedModel, 1, 100);
        $relatedKey = $related['meta']['primary_key'];

        $this->foreignKeyOptions[$column['column']] = collect($related['data'])->map(function (array $row) use ($relatedKey) {
            $preview = collect($row)->except([$relatedKey, 'is_trashed'])->first();

            return [
                'id' => $row[$relatedKey],
                'label' => $preview !== null ? "#{$row[$relatedKey]} — {$preview}" : "#{$row[$relatedKey]}",
            ];
        })->all();
    }

    public function create(ItemRepository $items): void
    {
        $this->errors = [];
        $this->jsonErrors = [];
        $payload = $this->formValues;

        foreach ($this->jsonDrafts as $column => $draft) {
            if ($draft === '') {
                continue;
            }

            $decoded = json_decode($draft, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->jsonErrors[$column] = true;

                continue;
            }

            $payload[$column] = $decoded;
        }

        if ($this->jsonErrors !== []) {
            return;
        }

        try {
            $created = $items->create($this->connection, $this->model, $payload);
        } catch (ItemValidationException $exception) {
            $this->errors = collect($exception->errors())->map(fn (array $messages) => $messages[0])->all();

            return;
        }

        // Pas de navigate: true : garantit un rendu frais de la fiche détail
        // du nouvel item, jamais servi depuis le cache client de wire:navigate.
        $this->redirect(route('modelbase.web.items.show', [
            'connection' => $this->connection,
            'model' => $this->model,
            'item' => $created['id'],
        ]));
    }

    public function render()
    {
        return view('modelbase::livewire.items.create');
    }
}
