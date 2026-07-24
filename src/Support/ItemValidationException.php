<?php

namespace Quatrebarbes\Modelbase\Support;

use RuntimeException;

/**
 * EX-417 : porte les erreurs de contrainte natives de la colonne (obligatoire,
 * unicité, format, clé étrangère) rencontrées lors d'une création/modification
 * d'item, telles que traduites par DatabaseErrorTranslator à partir de la
 * QueryException levée par le moteur de BDD — le plug-in ne redéfinit jamais
 * ces règles lui-même, il ne fait que relayer leur verdict.
 */
class ItemValidationException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Validation échouée.');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
