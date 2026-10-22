@php
    // EX-451 : ordre de la grille = ordre d'exposition des colonnes
    // (EX-422), champs pleine largeur (JSON/texte long, EX-450) déplacés en
    // fin de grille sans changer l'ordre relatif au sein de chaque groupe.
    $orderedValues = collect($itemData['values'])->sortBy(fn ($v) => ($v['type'] === 'json' || $v['long']) ? 1 : 0)->values();
    $orderedColumns = collect($columns)->sortBy(fn ($c) => ($c['type'] === 'json' || $c['long']) ? 1 : 0)->values();
@endphp
<div>
    @include('modelbase::livewire.partials._breadcrumb', ['items' => [
        ['label' => __('modelbase::common.databases'), 'url' => route('modelbase.web.connections.index')],
        ['label' => $connection, 'url' => route('modelbase.web.models.index', ['connection' => $connection])],
        ['label' => $model, 'url' => route('modelbase.web.items.index', ['connection' => $connection, 'model' => $model])],
        ['label' => __('modelbase::itemDetail.breadcrumbItem', ['item' => $item])],
    ]])

    <h1>
        {{ __('modelbase::itemDetail.title', ['model' => $model, 'item' => $item]) }}
        @if ($itemData['is_trashed'])
            <span>({{ __('modelbase::itemDetail.trashedBadge') }})</span>
        @endif
    </h1>

    <div class="toolbar__actions">
        @if (! $itemData['is_trashed'])
            <button type="button" class="btn" wire:click="startEdit" @if ($editing) disabled @endif>{{ __('modelbase::itemDetail.edit') }}</button>
            <button
                type="button"
                class="btn btn--danger"
                wire:click="delete"
                wire:confirm="{{ $itemData['soft_deletes'] ? __('modelbase::itemDetail.confirmSoftDelete') : __('modelbase::itemDetail.confirmDelete') }}"
            >{{ __('modelbase::itemDetail.delete') }}</button>
        @else
            <button type="button" class="btn" wire:click="restore">{{ __('modelbase::itemDetail.restore') }}</button>
            <button
                type="button"
                class="btn btn--danger"
                wire:click="forceDelete"
                wire:confirm="{{ __('modelbase::itemDetail.confirmForceDelete') }}"
            >{{ __('modelbase::itemDetail.forceDelete') }}</button>
        @endif

        @if ($itemData['is_searchable'])
            <button type="button" class="btn" wire:click="reindex">{{ __('modelbase::itemDetail.reindex') }}</button>
        @endif
    </div>

    @if ($deleteError)
        <p>{{ $deleteError }}</p>
    @endif
    @if ($forceDeleteError)
        <p>{{ $forceDeleteError }}</p>
    @endif

    @if (! empty($errors['_general']))
        <p class="item-form__error item-form__error--general">{{ $errors['_general'] }}</p>
    @endif

    @if ($editing)
        <form wire:submit.prevent="save">
            <div class="field-grid">
                @foreach ($orderedColumns as $column)
                    @include('modelbase::livewire.items.partials._field', ['column' => $column, 'bind' => "formValues.{$column['column']}"])
                @endforeach

                <div class="item-form__actions">
                    <button type="submit" class="btn btn--primary" @if (! $this->hasChanges()) disabled @endif>{{ __('modelbase::itemForm.save') }}</button>
                    <button type="button" class="btn" wire:click="cancelEdit">{{ __('modelbase::itemForm.cancel') }}</button>
                </div>
            </div>
        </form>
    @else
        <dl class="item-detail field-grid">
            @foreach ($orderedValues as $value)
                <div class="field-grid__field @if ($value['type'] === 'json' || $value['long']) field-grid__field--wide @endif">
                    <dt class="field-grid__label">{{ $value['column'] }}</dt>
                    <dd>
                        @if ($value['is_null'])
                            <span class="item-detail__null">{{ __('modelbase::itemDetail.null') }}</span>
                        @elseif ($value['type'] === 'foreign_key')
                            @if ($value['foreign_key']['navigable'])
                                <a href="{{ route('modelbase.web.items.show', ['connection' => $connection, 'model' => $value['foreign_key']['model'], 'item' => $value['value']]) }}">{{ $value['value'] }} ({{ $value['foreign_key']['model'] }})</a>
                            @else
                                <span class="item-detail__broken-fk">{{ $value['value'] }} — {{ __('modelbase::itemDetail.brokenForeignKey') }}</span>
                            @endif
                        @elseif ($value['type'] === 'boolean')
                            {{ $value['value'] ? __('modelbase::itemDetail.yes') : __('modelbase::itemDetail.no') }}
                        @elseif ($value['type'] === 'json')
                            <pre>{{ json_encode($this->decodeJsonColumn($value['value']), JSON_PRETTY_PRINT) }}</pre>
                        @elseif ($value['value'] === '')
                            <span class="item-detail__empty-string">{{ __('modelbase::itemDetail.emptyString') }}</span>
                        @else
                            {{ $value['value'] }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if ($relations !== [])
        <div class="relation-tabs">
            @if (count($relations) > 1)
                <div class="relation-tabs__bar" role="tablist">
                    @foreach ($relations as $relation)
                        <button
                            type="button"
                            role="tab"
                            class="relation-tabs__tab @if ($activeRelation === $relation['name']) relation-tabs__tab--active @endif"
                            wire:click="selectRelationTab('{{ $relation['name'] }}')"
                            aria-selected="{{ $activeRelation === $relation['name'] ? 'true' : 'false' }}"
                        >
                            {{ $relation['name'] }}
                        </button>
                    @endforeach
                </div>
            @endif

            @foreach ($relations as $relation)
                @if ($activeRelation === $relation['name'])
                    @livewire(\Quatrebarbes\Modelbase\Livewire\Relations\RelationTable::class, [
                        'connection' => $connection,
                        'model' => $model,
                        'item' => $item,
                        'relation' => $relation,
                        'showTitle' => count($relations) > 1,
                    ], key('relation-'.$relation['name']))
                @endif
            @endforeach
        </div>
    @endif
</div>
