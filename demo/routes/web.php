<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Application hôte de démo : aucune route web propre, le plug-in modelbase
// (quatrebarbes/modelbase) enregistre ses propres routes API/front.

// Route de confort réservée aux tests manuels en local : ce squelette
// Laravel n'a aucun scaffolding d'authentification (pas de Breeze/Fortify),
// et le plug-in ne fournit pas non plus de formulaire de connexion (EX-101
// s'appuie sur le guard de l'app hôte, qui reste de la responsabilité de
// celle-ci). Établit une session authentifiée pour l'utilisateur de démo
// seedé (cf. database/seeders/DatabaseSeeder.php), à ouvrir une fois dans le
// navigateur avant d'utiliser le front Nuxt. À retirer si l'app hôte de
// démo se dote un jour d'un vrai mécanisme de connexion.
if (app()->environment('local')) {
    Route::get('/dev-login', function () {
        Auth::login(User::firstOrFail());

        return response()->json(['message' => 'Authenticated as '.Auth::user()->email]);
    });
}
