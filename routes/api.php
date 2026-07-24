<?php

use Quatrebarbes\Modelbase\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

// Routes API du plug-in, sous le préfixe configurable (modelbase.route_prefix)
// suivi du segment "api" (EX-104/EX-105). Le middleware d'auth (EX-101/EX-103)
// s'applique à l'ensemble du groupe : seule l'authentification est vérifiée,
// aucune condition de rôle ni de droit utilisateur (EX-102).
Route::prefix(config('modelbase.route_prefix').'/api')
    ->middleware(Authenticate::class)
    ->name('modelbase.api.')
    ->group(function () {
        //
    });
