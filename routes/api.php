<?php

use Quatrebarbes\Modelbase\Http\Controllers\ConnectionController;
use Quatrebarbes\Modelbase\Http\Middleware\Authenticate;
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
    });
