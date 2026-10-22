<?php

namespace Quatrebarbes\Modelbase\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * EX-114 à EX-119 : locale de l'IHM du front Livewire, indépendante de la
 * locale de l'app hôte. EX-115 : le français est retenu par défaut sans
 * jamais lire l'en-tête Accept-Language du navigateur — seul le cookie
 * modelbase_locale (EX-117, posé par LocaleSwitcher) peut faire dévier de ce
 * défaut. Un cookie long plutôt que la session de l'app hôte : la session a
 * une durée de vie bornée, alors que EX-117 exige une persistance façon
 * localStorage (indéfinie, jusqu'à un changement explicite).
 */
class SetLocale
{
    public const COOKIE = 'modelbase_locale';

    public const SUPPORTED = ['fr', 'en'];

    public const DEFAULT = 'fr';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie(self::COOKIE);

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = self::DEFAULT;
        }

        App::setLocale($locale);

        return $next($request);
    }
}
