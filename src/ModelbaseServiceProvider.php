<?php

namespace Quatrebarbes\Modelbase;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Quatrebarbes\Modelbase\Livewire\Connections\ConnectionList;
use Quatrebarbes\Modelbase\Livewire\Items\ItemCreate;
use Quatrebarbes\Modelbase\Livewire\Items\ItemDetail;
use Quatrebarbes\Modelbase\Livewire\Items\ItemListing;
use Quatrebarbes\Modelbase\Livewire\Models\ModelList;
use Quatrebarbes\Modelbase\Livewire\Relations\RelationTable;
use Quatrebarbes\Modelbase\Livewire\Shared\LocaleSwitcher;

class ModelbaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/modelbase.php', 'modelbase');
    }

    public function boot(): void
    {
        // Enregistrement explicite obligatoire : Livewire ne sait reconstruire
        // une classe de composant à partir de son seul nom (`memo.name`, ex.
        // à chaque mise à jour AJAX post-rendu initial, cf.
        // ComponentRegistry::generateClassFromName()) que sous le namespace
        // configuré côté app hôte (`livewire.class_namespace`, par défaut
        // `App\Livewire`) — jamais sous celui d'un package tiers comme
        // `Quatrebarbes\Modelbase\Livewire\...`. Sans cet enregistrement, le
        // rendu initial d'une page fonctionne (résolution directe de la
        // classe par le routeur ou `@livewire(FQCN::class)`), mais toute
        // interaction suivante (pagination, tri, filtre, diagramme...) échoue
        // avec une `LivewireReleaseTokenMismatchException` trompeuse (« Page
        // Expired ») — Livewire ne trouvant pas la classe, pas un vrai
        // problème de session/CSRF.
        Livewire::component('modelbase-connections-list', ConnectionList::class);
        Livewire::component('modelbase-models-list', ModelList::class);
        Livewire::component('modelbase-items-listing', ItemListing::class);
        Livewire::component('modelbase-items-detail', ItemDetail::class);
        Livewire::component('modelbase-items-create', ItemCreate::class);
        Livewire::component('modelbase-relations-table', RelationTable::class);
        Livewire::component('modelbase-locale-switcher', LocaleSwitcher::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/modelbase.php' => config_path('modelbase.php'),
            ], 'modelbase-config');

            // Phase 35 : seul asset du front Livewire nécessitant encore un
            // build Node (Cytoscape.js, diagramme de relations EX-306 à
            // EX-311) — quelques Ko, sans rapport avec l'ampleur qu'avait
            // l'ancien build Nuxt complet (EX-106, supprimée en Phase 37 :
            // les vues/traductions du front sont chargées directement depuis
            // le package, cf. loadViewsFrom()/loadTranslationsFrom()
            // ci-dessous, sans aucune étape vendor:publish).
            $this->publishes([
                __DIR__.'/../resources/dist/modelbase-diagram' => public_path(config('modelbase.route_prefix').'/diagram-assets'),
            ], 'modelbase-diagram-assets');
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'modelbase');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'modelbase');

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }

    /**
     * Chemin de la feuille de style verbatim du front Livewire, inlinée par
     * resources/views/livewire/layout/app.blade.php — pas de publication ni
     * de route d'asset dédiée nécessaire pour un fichier de cette taille.
     */
    public static function cssPath(): string
    {
        return __DIR__.'/../resources/css/modelbase.css';
    }
}
