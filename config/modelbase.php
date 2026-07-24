<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Préfixe des routes du plug-in
    |--------------------------------------------------------------------------
    |
    | EX-104 : toutes les routes exposées par le plug-in (front et API) sont
    | servies sous ce préfixe. Configurable par l'application hôte, avec une
    | valeur par défaut permettant un fonctionnement sans configuration.
    |
    */
    'route_prefix' => env('MODELBASE_ROUTE_PREFIX', 'modelbase'),

    /*
    |--------------------------------------------------------------------------
    | Guard d'authentification
    |--------------------------------------------------------------------------
    |
    | EX-101 : le plug-in s'appuie sur le guard d'auth de l'app hôte, sans rôle
    | qui lui soit propre. `null` utilise le guard par défaut de l'application
    | (config('auth.defaults.guard')) ; l'app hôte peut en spécifier un autre
    | si son parcours plug-in doit être protégé par un guard dédié (ex. api).
    |
    */
    'guard' => env('MODELBASE_GUARD'),

    /*
    |--------------------------------------------------------------------------
    | Connexions exclues
    |--------------------------------------------------------------------------
    |
    | Noms des connexions déclarées dans config/database.php à ne jamais
    | lister (module 2), quel que soit leur statut de disponibilité.
    |
    */
    'excluded_connections' => [],

];
