<?php

namespace Quatrebarbes\Modelbase\Livewire\Shared;

use Illuminate\Support\Facades\Cookie;
use Livewire\Component;
use Quatrebarbes\Modelbase\Http\Middleware\SetLocale;

/**
 * EX-116 : sélecteur de langue visible dans l'IHM. EX-117 : le choix est
 * persisté (cookie longue durée posé par ce composant, lu par le middleware
 * SetLocale) et réappliqué aux visites suivantes.
 */
class LocaleSwitcher extends Component
{
    public function switchTo(string $locale): void
    {
        if (! in_array($locale, SetLocale::SUPPORTED, true)) {
            $locale = SetLocale::DEFAULT;
        }

        Cookie::queue(Cookie::forever(SetLocale::COOKIE, $locale));

        // Rechargement plutôt que ré-affichage in-place : les autres
        // composants de la page ont déjà rendu leurs chaînes traduites sous
        // l'ancienne locale au moment où cette action s'exécute — un aller-
        // retour complet garantit que tout le monde remonte sous la nouvelle
        // locale, sans mécanisme ad hoc de rafraîchissement inter-composants.
        // `window.location.reload()` plutôt que `$this->redirect(request()->
        // fullUrl())` : cette action est appelée via une requête AJAX
        // (`/livewire/update`), donc `request()` y désigne cette requête même,
        // pas la page affichée par le navigateur — le redirect partait vers
        // `/livewire/update` lui-même (bug constaté). Le navigateur connaît
        // déjà sa propre URL, `reload()` n'a besoin d'aucune URL calculée
        // côté serveur.
        $this->js('window.location.reload()');
    }

    public function render()
    {
        return view('modelbase::livewire.shared.locale-switcher', [
            'current' => app()->getLocale(),
            'locales' => SetLocale::SUPPORTED,
        ]);
    }
}
