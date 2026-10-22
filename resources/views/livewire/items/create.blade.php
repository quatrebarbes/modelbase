@php
    $orderedColumns = collect($columns)->sortBy(fn ($c) => ($c['type'] === 'json' || $c['long']) ? 1 : 0)->values();
@endphp
<div>
    @include('modelbase::livewire.partials._breadcrumb', ['items' => [
        ['label' => __('modelbase::common.databases'), 'url' => route('modelbase.web.connections.index')],
        ['label' => $connection, 'url' => route('modelbase.web.models.index', ['connection' => $connection])],
        ['label' => $model, 'url' => route('modelbase.web.items.index', ['connection' => $connection, 'model' => $model])],
        ['label' => __('modelbase::itemCreate.breadcrumbNew')],
    ]])

    <h1>{{ __('modelbase::itemCreate.title', ['model' => $model]) }}</h1>

    @if (! empty($errors['_general']))
        <p class="item-form__error item-form__error--general">{{ $errors['_general'] }}</p>
    @endif

    <form wire:submit.prevent="create">
        <div class="field-grid">
            @foreach ($orderedColumns as $column)
                @include('modelbase::livewire.items.partials._field', ['column' => $column, 'bind' => "formValues.{$column['column']}"])
            @endforeach

            <div class="item-form__actions">
                <button type="submit" class="btn btn--primary">{{ __('modelbase::itemCreate.create') }}</button>
            </div>
        </div>
    </form>
</div>
