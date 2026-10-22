<div class="relation-table" x-data="{ perPageKey: @js($this->perPageStorageKey()) }" x-init="
    const stored = localStorage.getItem('modelbase:per_page:' + perPageKey);
    if (stored && stored != {{ $perPage }}) {
        $wire.set('perPage', parseInt(stored, 10));
    }
" @modelbase-per-page-changed.window="localStorage.setItem('modelbase:per_page:' + $event.detail.context, $event.detail.perPage)">
    @if ($showTitle)
        <h2>{{ $relation['name'] }}</h2>
    @endif

    @if (! $relation['navigable'])
        <p class="relation-table__unavailable">{{ __('modelbase::relations.unavailableConnection', ['connection' => $relation['related_connection']]) }}</p>
    @elseif ($listing === null)
        <p class="relation-table__unavailable">{{ __('modelbase::relations.unavailableConnection', ['connection' => $relation['related_connection']]) }}</p>
    @elseif ($listing['data'] === [] && $filters === [] && $sort === '')
        <p>{{ __('modelbase::relations.empty') }}</p>
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
                            <button type="button" class="data-table__sort-btn" x-data @click="$wire.toggleSort('{{ $column['column'] }}', $event.shiftKey)">
                                {{ $column['column'] }}
                                @if ($dir)
                                    <span class="data-table__sort-indicator">{{ $dir === 'asc' ? '▲' : '▼' }}</span>
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
                    <tr wire:key="rel-row-{{ $row[$listing['meta']['primary_key']] }}" x-data @click="window.location.href = {{ Js::from($this->itemUrl($row[$listing['meta']['primary_key']])) }}" tabindex="0">
                        @foreach ($columns as $column)
                            <td>
                                <div class="data-table__cell">
                                    @php $value = $row[$column['column']] ?? null; @endphp
                                    @if (is_null($value))
                                        <em>{{ __('modelbase::itemDetail.null') }}</em>
                                    @elseif ($column['type'] === 'boolean')
                                        {{ $value ? __('modelbase::itemDetail.yes') : __('modelbase::itemDetail.no') }}
                                    @else
                                        {{ $value }}
                                    @endif
                                </div>
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) }}">{{ __('modelbase::relations.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($listing['meta']['total'] > 0)
            <div class="item-pagination-row">
                @include('modelbase::livewire.partials._pagination', ['meta' => $listing['meta']])
            </div>
        @endif
    @endif
</div>
