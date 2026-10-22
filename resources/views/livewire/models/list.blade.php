<div
    x-data="modelbaseModelList({
        models: @js($models),
        urls: @js(collect($models)->mapWithKeys(fn ($m) => [$m['name'] => $this->itemsUrl($m['name'])])),
        tableMissingLabel: @js(__('modelbase::models.tableMissing')),
    })"
>
    @include('modelbase::livewire.partials._breadcrumb', ['items' => [
        ['label' => __('modelbase::common.databases'), 'url' => route('modelbase.web.connections.index')],
        ['label' => $connection],
    ]])

    <h1>{{ __('modelbase::models.title', ['connection' => $connection]) }}</h1>

    <div class="toolbar">
        <input
            type="search"
            class="toolbar__search"
            x-model="search"
            placeholder="{{ __('modelbase::models.searchPlaceholder') }}"
        >
    </div>

    <template x-if="models.length === 0">
        <p>{{ __('modelbase::models.empty') }}</p>
    </template>

    <table class="data-table" x-show="models.length > 0">
        <thead>
            <tr>
                <template x-for="column in ['name', 'table', 'item_count_raw', 'property_count']" :key="column">
                    <th :class="{ 'model-list__count-column': column === 'item_count_raw' || column === 'property_count' }">
                        <button type="button" class="data-table__sort-btn" @click="toggleSort(column)" x-text="columnLabel(column)"></button>
                        <span class="data-table__sort-indicator" x-show="sortColumn === column" x-text="sortDir === 'asc' ? '▲' : '▼'"></span>
                    </th>
                </template>
            </tr>
        </thead>
        <tbody>
            <template x-for="model in sorted" :key="model.name">
                <tr
                    :class="{ 'model-list__row--disabled': !model.table_exists }"
                    :tabindex="model.table_exists ? 0 : -1"
                    @click="go(model)"
                    @keydown.enter="go(model)"
                >
                    <td x-text="model.name"></td>
                    <td x-text="model.table_exists ? model.table : tableMissingLabel"></td>
                    <td class="model-list__count model-list__count-column" x-text="model.item_count"></td>
                    <td class="model-list__count model-list__count-column" x-text="model.property_count"></td>
                </tr>
            </template>
        </tbody>
    </table>
</div>

@once
    <script>
        document.addEventListener('alpine:init', () => {
            const LABELS = {
                name: @json(__('modelbase::models.columnName')),
                table: @json(__('modelbase::models.columnTable')),
                item_count_raw: @json(__('modelbase::models.columnItems')),
                property_count: @json(__('modelbase::models.columnProperties')),
            };

            // EX-313 à EX-316 : tri mono-critère (contrairement au tri
            // multi-colonnes du listing des items), cycle asc -> desc ->
            // aucun tri, appliqué après le filtre nom/table (EX-304/EX-316).
            Alpine.data('modelbaseModelList', (config) => ({
                models: config.models,
                search: '',
                sortColumn: null,
                sortDir: 'asc',
                tableMissingLabel: config.tableMissingLabel,
                columnLabel(column) {
                    return LABELS[column];
                },
                toggleSort(column) {
                    if (this.sortColumn !== column) {
                        this.sortColumn = column;
                        this.sortDir = 'asc';
                    } else if (this.sortDir === 'asc') {
                        this.sortDir = 'desc';
                    } else {
                        this.sortColumn = null;
                    }
                },
                get filtered() {
                    const needle = this.search.trim().toLowerCase();

                    if (needle === '') {
                        return this.models;
                    }

                    return this.models.filter((model) => model.name.toLowerCase().includes(needle)
                        || model.table.toLowerCase().includes(needle));
                },
                get sorted() {
                    if (this.sortColumn === null) {
                        return this.filtered;
                    }

                    const column = this.sortColumn;
                    const dir = this.sortDir === 'asc' ? 1 : -1;

                    return [...this.filtered].sort((a, b) => {
                        // Un modèle sans table réelle n'a pas de nom de table
                        // comparable : toujours relégué en fin de tri, quel
                        // que soit le sens (EX-313, ModelList.vue historique).
                        if (column === 'table' && a.table_exists !== b.table_exists) {
                            return a.table_exists ? -1 : 1;
                        }

                        if (a[column] < b[column]) return -1 * dir;
                        if (a[column] > b[column]) return 1 * dir;

                        return 0;
                    });
                },
                go(model) {
                    if (model.table_exists) {
                        // Navigation en page complète (pas Livewire.navigate) :
                        // évite que le cache client de wire:navigate serve une
                        // liste d'items obsolète en revenant sur cette page.
                        window.location.href = config.urls[model.name];
                    }
                },
            }));
        });
    </script>
@endonce
