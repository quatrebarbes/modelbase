<?php

namespace Quatrebarbes\Modelbase\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;

/**
 * EX-105/EX-106 : sert le point d'entrée (index.html) du SPA Nuxt publié par
 * `vendor:publish --tag=modelbase-assets` (cf. ModelbaseServiceProvider) —
 * les autres assets (_nuxt/**) restent de simples fichiers statiques sous
 * public/vendor/modelbase, servis directement par le serveur web, jamais par
 * cette route. Le routage interne du SPA (Vue Router, historique HTML5)
 * prend ensuite le relais côté navigateur, quel que soit le sous-chemin
 * demandé (routes/web.php capture tout sous {prefix}/app).
 */
class SpaController extends Controller
{
    public function __invoke(): Response
    {
        $index = public_path('vendor/modelbase/index.html');

        if (! File::exists($index)) {
            return response(
                "Les assets du front n'ont pas été publiés : exécutez `php artisan vendor:publish --tag=modelbase-assets`.",
                500
            );
        }

        return response(File::get($index), 200, ['Content-Type' => 'text/html']);
    }
}
