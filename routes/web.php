<?php

use Illuminate\Support\Facades\Route;
use Quatrebarbes\Modelbase\Http\Middleware\Authenticate;
use Quatrebarbes\Modelbase\Http\Middleware\EnsureConnectionIsNavigable;
use Quatrebarbes\Modelbase\Http\Middleware\EnsureModelIsNavigable;
use Quatrebarbes\Modelbase\Http\Middleware\SetLocale;
use Quatrebarbes\Modelbase\Livewire\Connections\ConnectionList;
use Quatrebarbes\Modelbase\Livewire\Items\ItemCreate;
use Quatrebarbes\Modelbase\Livewire\Items\ItemDetail;
use Quatrebarbes\Modelbase\Livewire\Items\ItemListing;
use Quatrebarbes\Modelbase\Livewire\Models\ModelList;

// Route front du plug-in (Phase 37 : bascule du front Nuxt/EX-106 vers
// Livewire — cf. docs/roadmap.md, section « Réécriture du front : Nuxt →
// Livewire »), sous le préfixe configurable (modelbase.route_prefix) suivi
// du segment "app" — distinct du segment "api" de routes/api.php (EX-105).
// Un composant full-page par niveau de navigation, résolu par le routeur
// comme n'importe quelle action de contrôleur : les middlewares
// EnsureConnectionIsNavigable/EnsureModelIsNavigable (EX-206, EX-102)
// s'appliquent donc avant même l'instanciation du composant. Même
// middleware d'authentification que l'API (EX-101/EX-103), complété par
// SetLocale (EX-114 à EX-119) : aucune redirection vers une page de
// connexion, y compris pour une requête de navigateur classique.
Route::prefix(config('modelbase.route_prefix').'/app')
    ->middleware(['web', Authenticate::class, SetLocale::class])
    ->name('modelbase.web.')
    ->group(function () {
        Route::get('/', ConnectionList::class)->name('connections.index');

        Route::prefix('/connections/{connection}')
            ->middleware(EnsureConnectionIsNavigable::class)
            ->group(function () {
                Route::get('/models', ModelList::class)->name('models.index');

                Route::prefix('/models/{model}')
                    ->middleware(EnsureModelIsNavigable::class)
                    ->group(function () {
                        Route::get('/items', ItemListing::class)->name('items.index');

                        // Enregistrée avant "/items/{item}" : sans quoi le
                        // segment "create" serait capturé par {item}.
                        Route::get('/items/create', ItemCreate::class)->name('items.create');

                        Route::get('/items/{item}', ItemDetail::class)->name('items.show');
                    });
            });
    });

// Confort : accéder au préfixe nu (sans le segment "app") redirige vers le
// point d'entrée du front plutôt que de renvoyer une 404.
Route::prefix(config('modelbase.route_prefix'))
    ->middleware('web')
    ->group(function () {
        Route::redirect('/', '/'.config('modelbase.route_prefix').'/app')
            ->name('modelbase.web.redirect');
    });
