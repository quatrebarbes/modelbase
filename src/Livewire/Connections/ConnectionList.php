<?php

namespace Quatrebarbes\Modelbase\Livewire\Connections;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Quatrebarbes\Modelbase\Support\ConnectionRepository;

/**
 * Module 2 (EX-201 à EX-215) : listing des connexions déclarées par l'app
 * hôte. EX-209 : nom/driver affichés immédiatement (résolus ici, en PHP) ;
 * statut et nombre de modèles (EX-202/EX-205/EX-208) sont résolus à part,
 * connexion par connexion (EX-210/EX-211), par un appel JS direct à l'API
 * existante (`GET .../api/connections/{connection}/status`, inchangée)
 * plutôt que par un aller-retour Livewire — une requête Livewire groupe par
 * défaut plusieurs mises à jour de composants déclenchées dans le même tick
 * en un seul cycle serveur, ce qui romprait précisément l'indépendance
 * qu'EX-210/EX-211 exigent (une connexion lente ou injoignable ne doit
 * jamais retarder l'affichage du statut des autres, cf. Phase 16).
 */
#[Layout('modelbase::livewire.layout.app')]
class ConnectionList extends Component
{
    /** @var array<int, array{name: string, driver: string|null}> */
    public array $connections = [];

    public function mount(ConnectionRepository $connections): void
    {
        $this->connections = $connections->all();
    }

    public function statusUrl(string $connection): string
    {
        return route('modelbase.api.connections.status', ['connection' => $connection]);
    }

    public function modelsUrl(string $connection): string
    {
        return route('modelbase.web.models.index', ['connection' => $connection]);
    }

    public function render()
    {
        return view('modelbase::livewire.connections.list');
    }
}
