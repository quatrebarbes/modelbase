<?php

namespace Quatrebarbes\Modelbase\Livewire\Models;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Quatrebarbes\Modelbase\Support\ModelRepository;

/**
 * Module 3 (EX-301 à EX-305, EX-313 à EX-316) : listing des modèles Eloquent
 * déclarés par l'app hôte pour une connexion. La connexion est déjà garantie
 * navigable par le middleware de route (EnsureConnectionIsNavigable). Le
 * filtre (EX-304) et le tri (EX-313 à EX-316) restent entièrement côté
 * client (Alpine, cf. la vue) : la liste est chargée en un seul appel ici,
 * comme le faisait déjà `ModelList.vue` — un aller-retour serveur par
 * frappe/clic de tri serait une régression, pas un simple portage.
 */
#[Layout('modelbase::livewire.layout.app')]
class ModelList extends Component
{
    public string $connection;

    /** @var array<int, array{name: string, table: string, table_exists: bool, item_count: string, item_count_raw: int, property_count: int}> */
    public array $models = [];

    public function mount(string $connection, ModelRepository $models): void
    {
        $this->connection = $connection;
        $this->models = $models->forConnection($connection);
    }

    public function itemsUrl(string $model): string
    {
        return route('modelbase.web.items.index', [
            'connection' => $this->connection,
            'model' => $model,
        ]);
    }

    public function render()
    {
        return view('modelbase::livewire.models.list');
    }
}
