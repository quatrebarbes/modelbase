<?php

use Quatrebarbes\Modelbase\Http\Controllers\SpaController;
use Quatrebarbes\Modelbase\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

// Route front du plug-in, sous le préfixe configurable (modelbase.route_prefix)
// suivi du segment "app" — distinct du segment "api" de routes/api.php
// (EX-105). Sert uniquement le point d'entrée du SPA Nuxt publié via
// vendor:publish (EX-106, cf. SpaController) ; le routage interne (Vue
// Router) prend le relais côté navigateur pour tout sous-chemin, d'où le
// catch-all "{any?}". Même middleware d'authentification que l'API
// (EX-101/EX-103) : aucune redirection vers une page de connexion, y compris
// pour une requête de navigateur classique.
Route::prefix(config('modelbase.route_prefix').'/app')
    ->middleware(['web', Authenticate::class])
    ->group(function () {
        Route::get('/{any?}', SpaController::class)
            ->where('any', '.*')
            ->name('modelbase.web.app');
    });

// Confort : accéder au préfixe nu (sans le segment "app") redirige vers le
// point d'entrée du SPA plutôt que de renvoyer une 404.
Route::prefix(config('modelbase.route_prefix'))
    ->middleware('web')
    ->group(function () {
        Route::redirect('/', '/'.config('modelbase.route_prefix').'/app')
            ->name('modelbase.web.redirect');
    });
