<?php

namespace Quatrebarbes\Modelbase\Livewire\Relations;

use Livewire\Component;
use Quatrebarbes\Modelbase\Livewire\Concerns\FiltersAndSortsColumns;
use Quatrebarbes\Modelbase\Support\ItemFilterException;
use Quatrebarbes\Modelbase\Support\ItemRepository;
use Quatrebarbes\Modelbase\Support\RelationRepository;
use Quatrebarbes\Modelbase\Support\RelationUnavailableException;

/**
 * EX-425 à EX-431, EX-470 à EX-473 : tableau paginé des objets liés d'une
 * relation déclarée par l'item courant. Un composant par relation, état
 * (page/filtre/tri) entièrement local à cette instance et **jamais reflété
 * dans l'URL** (EX-471 : plusieurs tableaux coexistent sur une même fiche
 * détail, contrairement au listing principal) — pas de `#[Url]` ici, à la
 * différence d'`ItemListing`.
 */
class RelationTable extends Component
{
    use FiltersAndSortsColumns;

    public string $connection;

    public string $model;

    public string $item;

    /** @var array{name: string, type: string, multiplicity: string, related_model: string, related_connection: string, related_table: string, navigable: bool} */
    public array $relation;

    public bool $showTitle = true;

    public int $page = 1;

    public int $perPage = 10;

    public array $filters = [];

    public string $sort = '';

    /** @var array<int, array{name: string, type: string, technical: bool, fillable: bool, long: bool}> */
    public array $columns = [];

    public function mount(string $connection, string $model, string $item, array $relation, bool $showTitle, ItemRepository $items): void
    {
        $this->connection = $connection;
        $this->model = $model;
        $this->item = $item;
        $this->relation = $relation;
        $this->showTitle = $showTitle;

        // EX-473 : aucune colonne interrogée si la connexion cible est
        // indisponible, pas plus que le tableau lui-même (cf. render()).
        if ($relation['navigable']) {
            $this->columns = $items->columns($relation['related_connection'], $relation['related_model']);
        }
    }

    public function toggleSort(string $column, bool $additive = false): void
    {
        $this->sort = $this->toggleSortString($this->sort, $column, $additive);
        $this->page = 1;
    }

    public function updatedFilters(): void
    {
        $this->page = 1;
    }

    public function updatedPerPage(): void
    {
        $this->page = 1;
        $this->dispatch('modelbase-per-page-changed', context: $this->perPageStorageKey(), perPage: $this->perPage);
    }

    /**
     * EX-455/EX-456 : mémorisé par relation (connexion + modèle + nom de la
     * relation), pas par item — plusieurs items du même modèle partagent
     * donc la même préférence pour une relation donnée (même format de clé
     * que l'ancien `usePersistedPerPage('relation:...')`).
     */
    public function perPageStorageKey(): string
    {
        return "relation:{$this->connection}:{$this->model}:{$this->relation['name']}";
    }

    public function itemUrl(mixed $id): string
    {
        return route('modelbase.web.items.show', [
            'connection' => $this->relation['related_connection'],
            'model' => $this->relation['related_model'],
            'item' => $id,
        ]);
    }

    public function render(RelationRepository $relations)
    {
        if (! $this->relation['navigable']) {
            return view('modelbase::livewire.relations.table', ['listing' => null]);
        }

        [$filters, $invalidFilters] = $this->partitionFilters();

        try {
            $listing = $relations->paginateRelated(
                $this->connection,
                $this->model,
                $this->item,
                $this->relation['name'],
                $this->page,
                $this->perPage,
                $filters,
                $this->sort !== '' ? $this->sort : null,
            );
        } catch (RelationUnavailableException) {
            return view('modelbase::livewire.relations.table', ['listing' => null]);
        } catch (ItemFilterException) {
            $this->filters = [];
            $this->sort = '';
            $invalidFilters = [];
            $listing = $relations->paginateRelated($this->connection, $this->model, $this->item, $this->relation['name'], $this->page, $this->perPage);
        }

        return view('modelbase::livewire.relations.table', ['listing' => $listing, 'invalidFilters' => $invalidFilters]);
    }
}
