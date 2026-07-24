<?php

use Quatrebarbes\Modelbase\Http\Controllers\ConnectionController;
use Quatrebarbes\Modelbase\Http\Controllers\ItemController;
use Quatrebarbes\Modelbase\Http\Controllers\ModelController;
use Quatrebarbes\Modelbase\Http\Middleware\Authenticate;
use Quatrebarbes\Modelbase\Http\Middleware\EnsureConnectionIsNavigable;
use Quatrebarbes\Modelbase\Http\Middleware\EnsureModelIsNavigable;
use Illuminate\Support\Facades\Route;

// Routes API du plug-in, sous le préfixe configurable (modelbase.route_prefix)
// suivi du segment "api" (EX-104/EX-105). Le middleware d'auth (EX-101/EX-103)
// s'applique à l'ensemble du groupe : seule l'authentification est vérifiée,
// aucune condition de rôle ni de droit utilisateur (EX-102). Le groupe
// "web" est nécessaire pour que la session de l'app hôte (cookies) soit
// démarrée sur ces routes : sans lui, Auth::check() ne voit jamais
// l'utilisateur connecté, quel que soit le guard (EX-101 s'appuie sur le
// guard de l'app hôte, qui est session-based par défaut sur Laravel).
Route::prefix(config('modelbase.route_prefix').'/api')
    ->middleware(['web', Authenticate::class])
    ->name('modelbase.api.')
    ->group(function () {
        Route::get('/connections', [ConnectionController::class, 'index'])
            ->name('connections.index');

        // EX-206 : la navigation vers une connexion (et tout ce qu'elle
        // contient — modules 3-4) est bloquée si celle-ci n'est pas
        // disponible (cf. EnsureConnectionIsNavigable, déjà testé en
        // Phase 2 via une route sonde avant même l'existence de ces routes).
        Route::prefix('/connections/{connection}')
            ->middleware(EnsureConnectionIsNavigable::class)
            ->group(function () {
                Route::get('/models', [ModelController::class, 'index'])
                    ->name('connections.models.index');

                // EX-102 : même principe qu'EnsureConnectionIsNavigable un
                // niveau plus haut — l'accès aux items dépend uniquement de
                // la déclaration du modèle pour cette connexion (module 3).
                Route::prefix('/models/{model}')
                    ->middleware(EnsureModelIsNavigable::class)
                    ->group(function () {
                        // EX-412/EX-414/EX-415/EX-416 : schéma des colonnes,
                        // indépendant de l'existence d'un item.
                        Route::get('/columns', [ItemController::class, 'columns'])
                            ->name('connections.models.columns.index');

                        Route::get('/items', [ItemController::class, 'index'])
                            ->name('connections.models.items.index');

                        // EX-412 : création d'un item.
                        Route::post('/items', [ItemController::class, 'store'])
                            ->name('connections.models.items.store');

                        Route::get('/items/{item}', [ItemController::class, 'show'])
                            ->name('connections.models.items.show');

                        // EX-413 : modification d'un item existant (PUT/PATCH acceptés).
                        Route::match(['put', 'patch'], '/items/{item}', [ItemController::class, 'update'])
                            ->name('connections.models.items.update');

                        // EX-418/EX-420 : suppression d'un item existant.
                        Route::delete('/items/{item}', [ItemController::class, 'destroy'])
                            ->name('connections.models.items.destroy');
                    });
            });
    });
