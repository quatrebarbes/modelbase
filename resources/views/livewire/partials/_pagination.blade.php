{{--
    EX-452 à EX-454 : partagé entre le listing standard d'un modèle
    (ItemListing) et chaque tableau d'objets liés (RelationTable, EX-429 —
    « pagination identique au listing standard »background) — les deux
    composants exposent des propriétés publiques `$page`/`$perPage` de même
    nom, `wire:model` s'y lie donc sans paramètre à transmettre.
--}}
<div class="item-pagination">
    <div class="item-pagination__nav">
        <button
            type="button"
            class="btn item-pagination__step"
            aria-label="{{ __('modelbase::items.previousPage') }}"
            wire:click="$set('page', {{ max(1, $page - 1) }})"
            @disabled($meta['current_page'] <= 1)
        >‹</button>

        <span class="item-pagination__goto">
            {{ __('modelbase::items.pageLabel') }}
            <input
                type="number"
                min="1"
                max="{{ $meta['last_page'] }}"
                aria-label="{{ __('modelbase::items.goToPage') }}"
                class="item-pagination__control item-pagination__goto-input"
                wire:model.live.debounce.500ms="page"
            >
            {{ __('modelbase::items.pageOf', ['last' => $meta['last_page'], 'total' => $meta['total']]) }}
        </span>

        <button
            type="button"
            class="btn item-pagination__step"
            aria-label="{{ __('modelbase::items.nextPage') }}"
            wire:click="$set('page', {{ $page + 1 }})"
            @disabled($meta['current_page'] >= $meta['last_page'])
        >›</button>

        <span wire:loading class="spinner" role="status"></span>
    </div>

    <label class="item-pagination__per-page">
        {{ __('modelbase::items.perPage') }}
        <select class="item-pagination__control item-pagination__per-page-select" wire:model.live="perPage">
            @foreach ([10, 25, 50, 100] as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </select>
    </label>
</div>
