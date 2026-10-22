{{--
    Port de l'ancien Breadcrumb.vue (Phase 5) — jamais reporté en Phase 28+,
    régularisé après coup (signalé par l'utilisateur). $items : liste
    ordonnée de ['label' => string, 'url' => string|null] ; le dernier
    élément (ou tout élément sans url) est rendu comme page courante, non
    cliquable.
--}}
<nav aria-label="{{ __('modelbase::common.breadcrumbLabel') }}" class="breadcrumb">
    @foreach ($items as $item)
        @if (! $loop->first)
            <span class="breadcrumb__separator" aria-hidden="true">›</span>
        @endif
        @if ($loop->last || ! isset($item['url']))
            <span class="breadcrumb__current" aria-current="page">{{ $item['label'] }}</span>
        @else
            <a class="breadcrumb__link" href="{{ $item['url'] }}">{{ $item['label'] }}</a>
        @endif
    @endforeach
</nav>
