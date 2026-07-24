<?php

namespace Quatrebarbes\Modelbase\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * EX-101/EX-103 : applique le guard d'auth de l'app hôte (config('modelbase.guard'),
 * par défaut le guard de l'application) sans condition de rôle, et répond en JSON
 * 401 sans jamais rediriger vers une page de connexion.
 */
class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard(config('modelbase.guard'))->check()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
