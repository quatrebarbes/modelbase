<div x-data="{ perPageKey: @js($this->perPageStorageKey()) }" x-init="
    const stored = localStorage.getItem('modelbase:per_page:' + perPageKey);
    const params = new URLSearchParams(window.location.search);
    if (stored && !params.has('per_page')) {
        params.set('per_page', stored);
        window.location.href = window.location.pathname + '?' + params.toString();
    }
" @modelbase-per-page-changed.window="localStorage.setItem('modelbase:per_page:' + $event.detail.context, $event.detail.perPage)">
    @include('modelbase::livewire.partials._breadcrumb', ['items' => [
        ['label' => __('modelbase::common.databases'), 'url' => route('modelbase.web.connections.index')],
        ['label' => $connection, 'url' => route('modelbase.web.models.index', ['connection' => $connection])],
        ['label' => $model],
    ]])

    <h1>{{ __('modelbase::items.title', ['model' => $model]) }}</h1>

    <div class="toolbar">
        <div class="toolbar__actions">
            <a class="btn btn--primary" href="{{ $this->createUrl() }}">{{ __('modelbase::items.new') }}</a>
            <button type="button" class="btn" wire:click="toggleDiagram">{{ __('modelbase::relations.showDiagram') }}</button>
        </div>
        <div class="toolbar__actions">
            @if ($this->hasActiveFilterOrSort())
                <button type="button" class="btn" wire:click="clearFiltersAndSort">
                    {{ __('modelbase::items.clearFiltersAndSorts') }}
                </button>
            @endif

            @if ($listing['meta']['soft_deletes'])
                <select wire:model.live="trashed" aria-label="{{ __('modelbase::items.trashedFilterLabel') }}" class="btn">
                    <option value="">{{ __('modelbase::items.trashedFilterActiveOnly') }}</option>
                    <option value="with">{{ __('modelbase::items.trashedFilterWithTrashed') }}</option>
                    <option value="only">{{ __('modelbase::items.trashedFilterOnlyTrashed') }}</option>
                </select>
            @endif
        </div>
    </div>

    @if ($showDiagram)
        @php $diagramRelations = $this->diagramRelations(); @endphp
        <div class="relation-panel" @keydown.escape.window="$wire.toggleDiagram()">
            <div class="relation-panel__backdrop" wire:click="toggleDiagram"></div>

            <aside class="relation-panel__content" role="dialog" aria-modal="true">
                <div class="relation-diagram__header">
                    <h2>{{ __('modelbase::relations.diagramTitle', ['model' => $model]) }}</h2>
                    <button type="button" class="btn" wire:click="toggleDiagram">{{ __('modelbase::relations.close') }}</button>
                </div>

                @if ($diagramRelations === [])
                    <p>{{ __('modelbase::relations.diagramEmpty') }}</p>
                @else
                    <div
                        wire:ignore
                        x-data="modelbaseRelationDiagram({
                            model: @js($model),
                            relations: @js($diagramRelations),
                            unavailableLabel: @js(__('modelbase::relations.unavailableModel')),
                        })"
                        style="flex:1 1 auto;min-height:0;display:flex;flex-direction:column"
                    >
                        <p x-show="renderError">{{ __('modelbase::relations.diagramError') }}</p>
                        <div x-show="!renderError" x-ref="canvas" class="relation-diagram__canvas"></div>
                    </div>
                @endif
            </aside>
        </div>
    @endif

    @if ($listing['data'] === [] && ! $this->hasActiveFilterOrSort())
        <p>{{ __('modelbase::items.empty') }}</p>
    @else
        @php $sortState = $this->parseSort($sort); @endphp
        <table class="data-table">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        @php
                            $rank = collect($sortState)->search(fn ($s) => $s['column'] === $column['column']);
                            $dir = $rank !== false ? $sortState[$rank]['dir'] : null;
                        @endphp
                        <th>
                            <button
                                type="button"
                                class="data-table__sort-btn"
                                x-data
                                @click="$wire.toggleSort('{{ $column['column'] }}', $event.shiftKey)"
                            >
                                {{ $column['column'] }}
                                @if ($dir)
                                    <span class="data-table__sort-indicator">
                                        {{ $dir === 'asc' ? '▲' : '▼' }}@if (count($sortState) > 1)<sup>{{ $rank + 1 }}</sup>@endif
                                    </span>
                                @endif
                            </button>
                        </th>
                    @endforeach
                </tr>
                <tr class="item-list__filter-row">
                    @foreach ($columns as $column)
                        <th>
                            @if ($column['type'] !== 'json')
                                <input
                                    type="text"
                                    class="data-table__filter-input"
                                    aria-label="{{ $column['column'] }}"
                                    placeholder="{{ __('modelbase::items.filterPlaceholder') }}"
                                    wire:model.live.debounce.300ms="filters.{{ $column['column'] }}"
                                >
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($listing['data'] as $row)
                    <tr
                        wire:key="item-{{ $row[$listing['meta']['primary_key']] }}"
                        @class(['item-list__row--trashed' => $row['is_trashed'] ?? false])
                        x-data
                        @click="window.location.href = {{ Js::from($this->itemUrl($row[$listing['meta']['primary_key']])) }}"
                        @keydown.enter="window.location.href = {{ Js::from($this->itemUrl($row[$listing['meta']['primary_key']])) }}"
                        tabindex="0"
                    >
                        @foreach ($columns as $column)
                            <td>
                                <div class="data-table__cell">
                                    @php $value = $row[$column['column']] ?? null; @endphp
                                    @if ($column['column'] === 'deleted_at' && ($row['is_trashed'] ?? false))
                                        <span class="item-list__trashed-date">{{ $value }}</span>
                                    @elseif (is_null($value))
                                        <em>{{ __('modelbase::itemDetail.null') }}</em>
                                    @elseif ($column['type'] === 'boolean')
                                        {{ $value ? __('modelbase::itemDetail.yes') : __('modelbase::itemDetail.no') }}
                                    @elseif ($column['type'] === 'json')
                                        {{ is_string($value) ? $value : json_encode($value) }}
                                    @else
                                        {{ $value }}
                                    @endif
                                </div>
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) }}">{{ __('modelbase::items.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="item-pagination-row">
            @include('modelbase::livewire.partials._pagination', ['meta' => $listing['meta']])
        </div>
    @endif
</div>
