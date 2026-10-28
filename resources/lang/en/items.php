<?php

return [
    'title' => 'Items — :model',
    'empty' => 'No item available for this model.',
    'new' => '+ New item',
    'pageLabel' => 'Page',
    'pageOf' => '/ :last (:total items)',
    'goToPage' => 'Go to page',
    'previousPage' => 'Previous page',
    'nextPage' => 'Next page',
    'perPage' => 'Rows per page',
    'filterPlaceholder' => 'Filter…',
    'filterAll' => 'All',
    // EX-494: expected format of a column filter, shown while the input is invalid
    'filterInvalid' => [
        'number' => 'Expected format: number (e.g. 12, -3.5)',
        'datetime' => 'Expected format: YYYY, YYYY-MM, YYYY-MM-DD, YYYY-MM-DD HH, YYYY-MM-DD HH:MM or YYYY-MM-DD HH:MM:SS',
        'date' => 'Expected format: YYYY, YYYY-MM or YYYY-MM-DD',
        'time' => 'Expected format: HH, HH:MM or HH:MM:SS',
        'boolean' => 'Expected format: Yes or No',
        'text' => 'Expected format: text',
    ],
    'sortAscending' => 'Sort ascending',
    'sortDescending' => 'Sort descending',
    'clearFiltersAndSorts' => 'Clear filters & sorts',
    'trashedFilterLabel' => 'Deleted items filter',
    'trashedFilterActiveOnly' => 'Active items',
    'trashedFilterWithTrashed' => 'With deleted',
    'trashedFilterOnlyTrashed' => 'Deleted only',
];
