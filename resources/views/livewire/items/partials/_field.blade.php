{{--
    EX-414/EX-416/EX-421/EX-463 : widget adapté au type de colonne ; lecture
    seule si technique (EX-416) ou non fillable (EX-421/EX-464). $bind est le
    chemin de binding Livewire (ex. "formValues.name" ou "values.name").

    `wire:model.live` partout (pas `wire:model` seul, qui ne synchronise la
    valeur au serveur qu'au prochain aller-retour réseau) : le bouton
    Enregistrer est désactivé tant qu'`ItemDetail::hasChanges()` (calculée
    côté PHP à chaque rendu) ne détecte aucune différence — sans `.live`, cette
    méthode ne se réévalue jamais après une saisie (aucune requête déclenchée),
    et le bouton restait bloqué indéfiniment (bug signalé par l'utilisateur).
--}}
@php
    $readOnly = $column['technical'] || ! $column['fillable'];
@endphp
<div class="field-grid__field @if ($column['type'] === 'json' || $column['long']) field-grid__field--wide @endif">
    <label class="field-grid__label" for="field-{{ $column['column'] }}">{{ $column['column'] }}</label>

    <div class="item-form__control">
        @if ($readOnly)
            <input id="field-{{ $column['column'] }}" type="text" value="{{ data_get($formValues, $column['column']) }}" disabled>
        @elseif ($column['type'] === 'foreign_key')
            <select id="field-{{ $column['column'] }}" wire:model.live="{{ $bind }}">
                <option value="">—</option>
                @foreach ($foreignKeyOptions[$column['column']] ?? [] as $option)
                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
        @elseif ($column['type'] === 'boolean')
            <input class="item-form__checkbox" id="field-{{ $column['column'] }}" type="checkbox" wire:model.live="{{ $bind }}">
        @elseif ($column['type'] === 'number')
            <input id="field-{{ $column['column'] }}" type="number" step="any" wire:model.live.debounce.300ms="{{ $bind }}">
        @elseif ($column['type'] === 'date')
            <input id="field-{{ $column['column'] }}" type="datetime-local" wire:model.live="{{ $bind }}">
        @elseif ($column['type'] === 'json')
            <textarea id="field-{{ $column['column'] }}" rows="6" wire:model.live.debounce.300ms="jsonDrafts.{{ $column['column'] }}"></textarea>
            @if (! empty($jsonErrors[$column['column']]))
                <span class="item-form__error">{{ __('modelbase::itemForm.invalidJson') }}</span>
            @endif
        @elseif ($column['long'])
            <textarea id="field-{{ $column['column'] }}" rows="6" wire:model.live.debounce.300ms="{{ $bind }}"></textarea>
        @else
            <input id="field-{{ $column['column'] }}" type="text" wire:model.live.debounce.300ms="{{ $bind }}">
        @endif

        @if ($readOnly)
            <small class="item-form__technical">{{ $column['technical'] ? __('modelbase::itemForm.technicalReadOnly') : __('modelbase::itemForm.notEditable') }}</small>
        @endif

        @if (! empty($errors[$column['column']]))
            <span class="item-form__error">{{ $errors[$column['column']] }}</span>
        @endif
    </div>
</div>
