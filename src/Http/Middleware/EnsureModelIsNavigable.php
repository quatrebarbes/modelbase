<?php

namespace Quatrebarbes\Modelbase\Http\Middleware;

use Closure;
use Quatrebarbes\Modelbase\Support\ModelResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque côté API la navigation vers les items d'un modèle qui n'est pas
 * déclaré pour la connexion parente (EX-102 : la disponibilité de ce niveau
 * de navigation ne dépend que de celle du parent, jamais d'un droit
 * utilisateur) — appliqué aux routes imbriquées sous
 * `/connections/{connection}/models/{model}/...` (module 4), sur le même
 * principe qu'EnsureConnectionIsNavigable pour les connexions (module 2-3).
 */
class EnsureModelIsNavigable
{
    public function __construct(private ModelResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $connection = $request->route('connection');
        $model = $request->route('model');

        if (! is_string($connection)
            || ! is_string($model)
            || $this->resolver->resolve($connection, $model) === null
        ) {
            return response()->json(['message' => 'Modèle introuvable.'], 404);
        }

        return $next($request);
    }
}
