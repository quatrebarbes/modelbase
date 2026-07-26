<?php

namespace Quatrebarbes\Modelbase;

use Illuminate\Support\ServiceProvider;

class ModelbaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/modelbase.php', 'modelbase');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/modelbase.php' => config_path('modelbase.php'),
            ], 'modelbase-config');

            // EX-106 : assets compilés du front (SPA Nuxt statique, cf.
            // frontend/package.json `build:package`) publiés tels quels dans
            // l'app hôte, jamais servis dynamiquement par le plug-in — seule
            // la route de routes/web.php lit le index.html publié ici pour
            // amorcer le SPA, les autres fichiers (_nuxt/**) sont de simples
            // fichiers statiques servis directement par le serveur web.
            //
            // Publiés sous public/{route_prefix}/app, pas public/vendor/... :
            // le build Nuxt fige `app.baseURL` sur `/{route_prefix}/app/`
            // (cf. frontend/nuxt.config.ts), donc les <script src> de
            // index.html demandent leurs fichiers à cette URL précise. Un
            // chemin de publication différent laisserait ces requêtes tomber
            // dans le catch-all de routes/web.php, qui renverrait index.html
            // (text/html) au lieu du fichier JS attendu.
            $this->publishes([
                __DIR__.'/../resources/dist/modelbase' => public_path(config('modelbase.route_prefix').'/app'),
            ], 'modelbase-assets');
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
