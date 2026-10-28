{{-- EX-432, EX-475, EX-484 à EX-494 : champ de filtre d'une colonne, partagé par le listing et les tableaux d'objets liés. --}}
<th>
    @if ($column['type'] === 'boolean')
        {{-- EX-486 : liste à 3 choix, « Tous » = absence de filtre --}}
        <select
            class="data-table__filter-input"
            aria-label="{{ $column['column'] }}"
            wire:model.live="filters.{{ $column['column'] }}"
        >
            <option value="">{{ __('modelbase::items.filterAll') }}</option>
            <option value="1">{{ __('modelbase::itemDetail.yes') }}</option>
            <option value="0">{{ __('modelbase::itemDetail.no') }}</option>
        </select>
    @elseif ($column['type'] !== 'json')
        @php $invalidFormat = $invalidFilters[$column['column']] ?? null; @endphp
        <input
            type="text"
            @class(['data-table__filter-input', 'data-table__filter-input--invalid' => $invalidFormat !== null])
            aria-label="{{ $column['column'] }}"
            placeholder="{{ __('modelbase::items.filterPlaceholder') }}"
            wire:model.live.debounce.300ms="filters.{{ $column['column'] }}"
            @if ($invalidFormat !== null)
                aria-invalid="true"
                aria-describedby="{{ $filterErrorPrefix }}-{{ $column['column'] }}"
            @endif
        >
        @if ($invalidFormat !== null)
            {{-- EX-494 : format attendu pour le type de la colonne --}}
            <span id="{{ $filterErrorPrefix }}-{{ $column['column'] }}" class="data-table__filter-error">
                {{ __('modelbase::items.filterInvalid.'.$invalidFormat) }}
            </span>
        @endif
    @endif
</th>
