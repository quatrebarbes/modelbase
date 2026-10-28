<?php

return [
    'title' => 'Items — :model',
    'empty' => 'Aucun item disponible pour ce modèle.',
    'new' => '+ Nouvel item',
    'pageLabel' => 'Page',
    'pageOf' => '/ :last (:total items)',
    'goToPage' => 'Aller à la page',
    'previousPage' => 'Page précédente',
    'nextPage' => 'Page suivante',
    'perPage' => 'Lignes par page',
    'filterPlaceholder' => 'Filtrer…',
    'filterAll' => 'Tous',
    // EX-494 : format attendu par le filtre d'une colonne, affiché tant que la saisie est invalide
    'filterInvalid' => [
        'number' => 'Format attendu : nombre (ex. 12, -3,5)',
        'datetime' => 'Format attendu : AAAA, AAAA-MM, AAAA-MM-JJ, AAAA-MM-JJ HH, AAAA-MM-JJ HH:MM ou AAAA-MM-JJ HH:MM:SS',
        'date' => 'Format attendu : AAAA, AAAA-MM ou AAAA-MM-JJ',
        'time' => 'Format attendu : HH, HH:MM ou HH:MM:SS',
        'boolean' => 'Format attendu : Oui ou Non',
        'text' => 'Format attendu : texte',
    ],
    'sortAscending' => 'Tri croissant',
    'sortDescending' => 'Tri décroissant',
    'clearFiltersAndSorts' => 'Effacer filtres & tris',
    'trashedFilterLabel' => 'Filtre des items supprimés',
    'trashedFilterActiveOnly' => 'Items actifs',
    'trashedFilterWithTrashed' => 'Avec supprimés',
    'trashedFilterOnlyTrashed' => 'Supprimés uniquement',
];
