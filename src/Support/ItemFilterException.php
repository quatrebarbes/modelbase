<?php

namespace Quatrebarbes\Modelbase\Support;

use RuntimeException;

/**
 * EX-432 : porte les erreurs de nom de colonne de filtre/tri inconnu ou non
 * exposé par `ItemRepository::columnsFor()` (EX-422) — jamais un nom de
 * colonne brut passé tel quel à une requête SQL.
 */
class ItemFilterException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Filtre ou tri invalide.');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
