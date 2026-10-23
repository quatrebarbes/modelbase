<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Query\Builder;

/**
 * EX-432 à EX-436 : construit les clauses WHERE/ORDER BY du listing standard
 * d'items à partir des paramètres filter[...]/sort de la requête. Les noms de
 * colonnes sont toujours validés contre `$columnTypes` (l'allowlist calculée
 * par `ItemRepository::columnsFor()`, EX-422) avant toute construction de
 * requête — jamais un nom de colonne brut fourni par le client, pour écarter
 * tout risque d'injection via un nom de colonne/direction de tri.
 *
 * `$table` qualifie les colonnes par le nom de leur table, nécessaire quand
 * la requête joint d'autres tables partageant certains noms de colonnes
 * (relation hasManyThrough/belongsToMany, EX-472).
 */
class ItemQueryFilter
{
    /**
     * EX-433 : filtre "contient" insensible à la casse pour les colonnes de
     * type texte, égalité stricte pour les autres types (y compris clé
     * étrangère : égalité sur la valeur brute, pas de résolution). EX-434 :
     * plusieurs filtres appliqués au même Builder sont combinés en ET par
     * défaut chez Laravel, sans code dédié.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, ColumnType>  $columnTypes
     *
     * @throws ItemFilterException
     */
    public function applyFilters(Builder $query, array $filters, array $columnTypes, ?string $table = null): void
    {
        $errors = [];

        foreach ($filters as $column => $value) {
            if (! isset($columnTypes[$column])) {
                $errors[$column] = ["La colonne « {$column} » n'existe pas ou n'est pas filtrable."];

                continue;
            }

            if ($columnTypes[$column] === ColumnType::STRING) {
                $query->whereLike($this->qualify($column, $table), '%'.$value.'%');
            } else {
                $query->where($this->qualify($column, $table), $value);
            }
        }

        if ($errors !== []) {
            throw new ItemFilterException($errors);
        }
    }

    /**
     * EX-435 : tri sur une ou plusieurs colonnes, chacune avec sa direction —
     * liste séparée par des virgules, un nom de colonne préfixé de `-` triant
     * en ordre descendant. EX-436 : l'ordre de priorité entre colonnes de tri
     * est celui de la liste, `orderBy()` étant appelé dans cet ordre (l'ordre
     * d'appel est déjà l'ordre de priorité chez Laravel).
     *
     * @param  array<string, ColumnType>  $columnTypes
     *
     * @throws ItemFilterException
     */
    public function applySort(Builder $query, string $sort, array $columnTypes, ?string $table = null): void
    {
        $errors = [];

        foreach (explode(',', $sort) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            $direction = 'asc';
            $column = $segment;

            if (str_starts_with($segment, '-')) {
                $direction = 'desc';
                $column = substr($segment, 1);
            }

            if (! isset($columnTypes[$column])) {
                $errors[$column] = ["La colonne « {$column} » n'existe pas ou n'est pas triable."];

                continue;
            }

            $query->orderBy($this->qualify($column, $table), $direction);
        }

        if ($errors !== []) {
            throw new ItemFilterException($errors);
        }
    }

    private function qualify(string $column, ?string $table): string
    {
        return $table === null ? $column : $table.'.'.$column;
    }
}
