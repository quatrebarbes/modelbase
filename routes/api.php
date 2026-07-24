<?php

use Illuminate\Support\Facades\Route;

// Routes API du plug-in, sous le préfixe configurable (modelbase.route_prefix)
// suivi du segment "api" (EX-104/EX-105). Le middleware d'auth de la Phase 1
// (EX-101/EX-103) s'appliquera à ce groupe.
Route::prefix(config('modelbase.route_prefix').'/api')
    ->name('modelbase.api.')
    ->group(function () {
        //
    });
