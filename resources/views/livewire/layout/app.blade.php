<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Modelbase' }}</title>

    {{--
        EX-106 (supprimée) rendait nécessaire un vendor:publish pour les
        assets du front — le front Livewire n'a besoin d'aucune étape de
        publication : la feuille de style du plug-in est inlinée directement
        depuis le package (fichier verbatim, cf. resources/css/modelbase.css).
    --}}
    <style>{!! file_get_contents(\Quatrebarbes\Modelbase\ModelbaseServiceProvider::cssPath()) !!}</style>

    @livewireStyles
</head>
<body
    x-data="{ toasts: [] }"
    @toast.window="
        const id = Date.now() + Math.random();
        toasts.push({ id, message: $event.detail.message, type: $event.detail.type ?? 'success' });
        setTimeout(() => { toasts = toasts.filter(t => t.id !== id) }, 2500);
    "
>
    <header class="toolbar">
        <span></span>
        @livewire(\Quatrebarbes\Modelbase\Livewire\Shared\LocaleSwitcher::class)
    </header>

    <main>
        {{ $slot }}
    </main>

    {{--
        EX-446 : retour visuel de succès/échec pour les actions non
        bloquantes (restauration, réindexation, sauvegarde) — chaque
        composant se contente de `$this->dispatch('toast', message: ...,
        type: ...)`, un seul écouteur global ici les affiche tous, plutôt
        qu'un composant Toast dédié par page.
    --}}
    <div class="toast-stack">
        <template x-for="toast in toasts" :key="toast.id">
            <div
                class="toast"
                :class="{ 'toast--error': toast.type === 'error' }"
                @click="toasts = toasts.filter(t => t.id !== toast.id)"
                x-text="toast.message"
            ></div>
        </template>
    </div>

    @livewireScripts

    {{--
        Phase 35 : bundle Cytoscape.js du diagramme de relations (module 3,
        EX-306 à EX-311) — seul asset du front Livewire nécessitant un
        `vendor:publish` (tag modelbase-diagram-assets, cf.
        ModelbaseServiceProvider). Chargé partout (fichier de quelques Ko) :
        n'exécute rien tant qu'aucun composant n'invoque
        `modelbaseRelationDiagram`.
    --}}
    <script src="{{ asset(config('modelbase.route_prefix').'/diagram-assets/relation-diagram.js') }}" defer></script>
</body>
</html>
