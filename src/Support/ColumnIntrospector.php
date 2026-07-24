<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Introspecte les colonnes de la table d'un modèle (nom, type mappé vers
 * ColumnType, clé étrangère) via les méthodes natives de Schema (EX-407 en
 * préparation) — pas de dépendance à doctrine/dbal, retirée par Laravel 11+.
 * Une colonne participant à une clé étrangère composite (plusieurs colonnes)
 * n'est pas traitée comme clé étrangère au sens de ce module : hors périmètre
 * du modèle de données du module 4 (une ItemValue référence au plus un Item).
 */
class ColumnIntrospector
{
    /**
     * @return array<int, array{name: string, type: string, is_foreign_key: bool, foreign_key: array{table: string, column: string}|null}>
     */
    public function forTable(string $connection, string $table): array
    {
        $schema = Schema::connection($connection);
        $foreignKeys = $schema->getForeignKeys($table);

        return collect($schema->getColumns($table))
            ->map(function (array $column) use ($foreignKeys) {
                $foreignKey = $this->foreignKeyFor($column['name'], $foreignKeys);

                return [
                    'name' => $column['name'],
                    'type' => ($foreignKey !== null ? ColumnType::FOREIGN_KEY : $this->scalarType($column))->value,
                    'is_foreign_key' => $foreignKey !== null,
                    'foreign_key' => $foreignKey,
                ];
            })
            ->all();
    }

    /**
     * @param  list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string|null, on_delete: string|null}>  $foreignKeys
     * @return array{table: string, column: string}|null
     */
    private function foreignKeyFor(string $column, array $foreignKeys): ?array
    {
        foreach ($foreignKeys as $foreignKey) {
            if (count($foreignKey['columns']) === 1 && $foreignKey['columns'][0] === $column) {
                return [
                    'table' => $foreignKey['foreign_table'],
                    'column' => $foreignKey['foreign_columns'][0] ?? 'id',
                ];
            }
        }

        return null;
    }

    /**
     * @param  array{type: string, type_name: string}  $column
     */
    private function scalarType(array $column): ColumnType
    {
        $type = strtolower($column['type_name']);
        $full = strtolower($column['type']);

        // Noms de type non uniformes selon le driver (ex. bigint mysql/sqlite
        // vs int8 pgsql, timestamp vs timestamptz, bool vs boolean) : chaque
        // liste couvre les variantes observées sur mysql/pgsql/sqlite/sqlsrv.
        return match (true) {
            in_array($type, ['boolean', 'bool', 'bit'], true) || str_contains($full, 'tinyint(1)') => ColumnType::BOOLEAN,
            in_array($type, ['json', 'jsonb'], true) => ColumnType::JSON,
            in_array($type, ['date', 'datetime', 'datetime2', 'timestamp', 'timestamptz', 'time', 'timetz'], true) => ColumnType::DATE,
            in_array($type, [
                'integer', 'int', 'int2', 'int4', 'int8', 'bigint', 'mediumint', 'smallint', 'tinyint',
                'decimal', 'numeric', 'float', 'float4', 'float8', 'double', 'double precision', 'real', 'money',
            ], true) => ColumnType::NUMBER,
            default => ColumnType::STRING,
        };
    }
}
