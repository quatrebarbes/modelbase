<?php

namespace Quatrebarbes\Modelbase\Http\Middleware;

use Closure;
use Quatrebarbes\Modelbase\Support\ConnectionAvailability;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EX-206 : bloque côté API la navigation vers une connexion qui n'est pas
 * disponible — appliqué aux routes imbriquées sous `/connections/{connection}/...`
 * (modules 3-4). Une connexion inexistante ou exclue (`modelbase.excluded_connections`)
 * est traitée comme non trouvée ; une connexion configurée mais injoignable
 * (EX-204) est traitée comme un conflit, distinct d'une absence de ressource.
 */
class EnsureConnectionIsNavigable
{
    public function __construct(private ConnectionAvailability $availability)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route('connection');
        $connections = config('database.connections', []);
        $excluded = config('modelbase.excluded_connections', []);

        if (! is_string($name)
            || ! array_key_exists($name, $connections)
            || in_array($name, $excluded, true)
        ) {
            return response()->json(['message' => 'Connexion introuvable.'], 404);
        }

        if (! $this->availability->isAvailable($name)) {
            return response()->json(['message' => 'Connexion indisponible.'], 409);
        }

        return $next($request);
    }
}
