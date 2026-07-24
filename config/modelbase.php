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
    | Connexions exclues
    |--------------------------------------------------------------------------
    |
    | Noms des connexions déclarées dans config/database.php à ne jamais
    | lister (module 2), quel que soit leur statut de disponibilité.
    |
    */
    'excluded_connections' => [],

];
