<?php

namespace Quatrebarbes\Modelbase\Livewire\Items;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Quatrebarbes\Modelbase\Livewire\Concerns\FiltersAndSortsColumns;
use Quatrebarbes\Modelbase\Support\ItemFilterException;
use Quatrebarbes\Modelbase\Support\ItemRepository;
use Quatrebarbes\Modelbase\Support\RelationRepository;

/**
 * Module 4 (EX-401 à EX-404, EX-432 à EX-436, EX-452 à EX-456, EX-469,
 * EX-476) : listing paginé, filtrable et triable des items d'un modèle. La
 * connexion et le modèle sont déjà garantis navigables par les middlewares de
 * route. Filtre (debounce 300 ms) et tri restent côté serveur (contrairement
 * au listing des modèles, Phase 29) puisque les données elles-mêmes le sont
 * déjà — `#[Url]` synchronise l'état dans la query string sans entrée
 * d'historique supplémentaire, comme le faisait `router.replace()` côté Nuxt.
 */
#[Layout('modelbase::livewire.layout.app')]
class ItemListing extends Component
{
    use FiltersAndSortsColumns;

    public string $connection;

    public string $model;

    #[Url(history: false)]
    public int $page = 1;

    #[Url(history: false, as: 'per_page')]
    public int $perPage = 25;

    #[Url(history: false)]
    public array $filters = [];

    #[Url(history: false)]
    public string $sort = '';

    #[Url(history: false)]
    public string $trashed = '';

    /** @var array<int, array{column: string, type: string, technical: bool, fillable: bool, long: bool}> */
    public array $columns = [];

    public bool $showDiagram = false;

    public function mount(string $connection, string $model, ItemRepository $items): void
    {
        $this->connection = $connection;
        $this->model = $model;
        $this->columns = $items->columns($connection, $model);
    }

    public function toggleDiagram(): void
    {
        $this->showDiagram = ! $this->showDiagram;
    }

    /**
     * EX-306 à EX-311 : diagramme accessible depuis le listing des items,
     * calculé seulement à l'ouverture (le panneau n'est rendu que si
     * `showDiagram` est vrai, cf. la vue) plutôt qu'à chaque affichage de la
     * page.
     *
     * @return array<int, array{name: string, multiplicity: string, related_model: string, navigable: bool, url: ?string}>
     */
    public function diagramRelations(): array
    {
        return collect(app(RelationRepository::class)->forModel($this->connection, $this->model))
            ->map(fn (array $relation) => [
                'name' => $relation['name'],
                'multiplicity' => $relation['multiplicity'],
                'related_model' => $relation['related_model'],
                'navigable' => $relation['navigable'],
                'url' => $relation['navigable'] ? route('modelbase.web.items.index', [
                    'connection' => $relation['related_connection'],
                    'model' => $relation['related_model'],
                ]) : null,
            ])
            ->values()
            ->all();
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

    public function updatedTrashed(): void
    {
        $this->page = 1;
    }

    public function updatedPerPage(): void
    {
        $this->page = 1;
        $this->dispatch('modelbase-per-page-changed', context: $this->perPageStorageKey(), perPage: $this->perPage);
    }

    public function clearFiltersAndSort(): void
    {
        $this->filters = [];
        $this->sort = '';
        $this->page = 1;
    }

    public function hasActiveFilterOrSort(): bool
    {
        return $this->sort !== '' || array_filter($this->filters, fn ($v) => $v !== null && $v !== '') !== [];
    }

    public function perPageStorageKey(): string
    {
        return "model:{$this->connection}:{$this->model}";
    }

    public function createUrl(): string
    {
        return route('modelbase.web.items.create', [
            'connection' => $this->connection,
            'model' => $this->model,
        ]);
    }

    public function itemUrl(mixed $id): string
    {
        return route('modelbase.web.items.show', [
            'connection' => $this->connection,
            'model' => $this->model,
            'item' => $id,
        ]);
    }

    public function render(ItemRepository $items)
    {
        $filters = array_filter($this->filters, fn ($v) => $v !== null && $v !== '');

        try {
            $listing = $items->paginate(
                $this->connection,
                $this->model,
                $this->page,
                max(1, $this->perPage),
                $filters,
                $this->sort !== '' ? $this->sort : null,
                $this->trashed !== '' ? $this->trashed : null,
            );
        } catch (ItemFilterException) {
            // État de filtre/tri devenu invalide (ex. query string modifiée à
            // la main, ou schéma du modèle changé côté hôte) : repli sur un
            // état propre plutôt qu'une page en erreur.
            $this->filters = [];
            $this->sort = '';
            $listing = $items->paginate($this->connection, $this->model, $this->page, max(1, $this->perPage));
        }

        // EX-454 : la page réellement servie (après repli sur les bornes) est
        // resynchronisée dans l'URL, pour ne pas afficher un numéro de page
        // qui ne correspond plus aux données rendues.
        $this->page = $listing['meta']['current_page'];

        return view('modelbase::livewire.items.listing', ['listing' => $listing]);
    }
}
