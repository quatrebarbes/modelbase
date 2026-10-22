<div>
    <h1>{{ __('modelbase::common.databases') }}</h1>

    <table class="data-table">
        <thead>
            <tr>
                <th>{{ __('modelbase::connections.columnName') }}</th>
                <th>{{ __('modelbase::connections.columnDriver') }}</th>
                <th class="connection-list__status-column">{{ __('modelbase::connections.columnStatus') }}</th>
                <th class="connection-list__count-column">{{ __('modelbase::connections.columnModels') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($connections as $connection)
                <tr
                    wire:key="connection-{{ $connection['name'] }}"
                    x-data="modelbaseConnectionStatus({
                        statusUrl: @js($this->statusUrl($connection['name'])),
                        navigateUrl: @js($this->modelsUrl($connection['name'])),
                        labels: {
                            checking: @js(__('modelbase::connections.statusChecking')),
                            available: @js(__('modelbase::connections.statusAvailable')),
                            unavailable: @js(__('modelbase::connections.statusUnavailable')),
                        },
                    })"
                    :class="{ 'connection-list__row--unavailable': !navigable }"
                    :tabindex="navigable ? 0 : -1"
                    @click="go()"
                    @keydown.enter="go()"
                >
                    <td>{{ $connection['name'] }}</td>
                    <td>{{ $connection['driver'] ?? '—' }}</td>
                    <template x-if="status === 'checking'">
                        <td class="connection-list__status-column">
                            <span class="connection-list__checking">
                                <span class="spinner" role="status"></span> <span x-text="statusLabel"></span>
                            </span>
                        </td>
                    </template>
                    <template x-if="status !== 'checking'">
                        <td class="connection-list__status-column" x-text="statusLabel"></td>
                    </template>
                    <template x-if="status === 'checking'">
                        <td class="connection-list__count-column"></td>
                    </template>
                    <template x-if="status !== 'checking'">
                        <td class="connection-list__count connection-list__count-column" x-text="modelCount === null ? '—' : modelCount"></td>
                    </template>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@once
    <script>
        document.addEventListener('alpine:init', () => {
            // EX-210/EX-211 : chaque ligne résout son statut via un fetch()
            // indépendant, hors du cycle de requête Livewire (cf.
            // ConnectionList::class) — une connexion lente/injoignable ne
            // retarde jamais l'affichage des autres.
            Alpine.data('modelbaseConnectionStatus', (config) => ({
                status: 'checking',
                modelCount: null,
                init() {
                    fetch(config.statusUrl, {headers: {Accept: 'application/json'}})
                        .then((response) => (response.ok ? response.json() : Promise.reject(response)))
                        .then((data) => {
                            this.status = data.status;
                            this.modelCount = data.model_count;
                        })
                        .catch(() => {
                            this.status = 'unavailable';
                            this.modelCount = null;
                        });
                },
                get navigable() {
                    return this.status === 'available';
                },
                get statusLabel() {
                    return config.labels[this.status] ?? config.labels.checking;
                },
                go() {
                    if (this.navigable) {
                        // Navigation en page complète plutôt que
                        // Livewire.navigate() : wire:navigate met en cache
                        // côté client la dernière page visitée (retour
                        // arrière/avant instantané) — les données de statut
                        // (EX-210/EX-211) sont résolues côté client à
                        // l'ouverture de la page ci-dessus, un
                        // rechargement complet garantit qu'elles ne sont
                        // jamais affichées obsolètes.
                        window.location.href = config.navigateUrl;
                    }
                },
            }));
        });
    </script>
@endonce
