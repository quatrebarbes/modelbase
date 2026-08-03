<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

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
     * @return array<int, array{name: string, type: string, is_foreign_key: bool, foreign_key: array{table: string, column: string}|null, long: bool}>
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
                    // EX-450/EX-463 : signale une colonne de texte long (SQL
                    // text/mediumtext/longtext, par opposition à varchar/char)
                    // — un rendu adapté (EX-407) en tire parti côté front
                    // (éditeur multiligne EX-463, réordonnancement EX-451)
                    // sans en faire un type de rendu à part (reste
                    // ColumnType::STRING).
                    'long' => $this->isLongText($column),
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
     * EX-423 : détecte les clés étrangères déclarées via une relation
     * Eloquent `belongsTo` du modèle hôte (méthode publique sans paramètre
     * requis, invoquée pour inspecter l'objet Relation construit) — prend le
     * pas sur la seule contrainte FK de la base ci-dessus : une relation
     * déclarée sans contrainte FK réelle en base (cas courant, notamment
     * sqlite ou app hôte n'imposant pas la contrainte) est ainsi détectée.
     * Construire une BelongsTo n'exécute aucune requête (`addConstraints()`
     * ne fait qu'ajouter une clause `where` au futur query builder), invoquer
     * la méthode de relation est donc sans effet de bord — sous réserve de
     * ne jamais invoquer une méthode qui, elle, en aurait un (cf. RelationMethodGuard).
     *
     * @return array<string, array{table: string, column: string}>
     */
    public function relationForeignKeys(Model $instance): array
    {
        $class = new ReflectionClass($instance);
        $relations = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! RelationMethodGuard::isInvocable($method, $class)) {
                continue;
            }

            try {
                $relation = $method->invoke($instance);
            } catch (Throwable) {
                continue;
            }

            // `MorphTo` étend `BelongsTo` mais n'en est pas une au sens de
            // cette méthode : appelée sur une instance neuve (sans valeur
            // pour la colonne `*_type`), sa cible se résout à l'instance
            // elle-même (`getRelated()` renvoie le modèle courant), ce qui
            // produirait une clé étrangère auto-référencée absurde. Une
            // relation polymorphique n'a de toute façon pas une table cible
            // unique, hors périmètre d'EX-423 (une seule table référencée).
            if (! $relation instanceof BelongsTo || $relation instanceof MorphTo) {
                continue;
            }

            $foreignKeyName = $relation->getForeignKeyName();

            // Clé étrangère composite (ex. relation déclarée via un package
            // type Compoships) : getForeignKeyName() renvoie alors un array
            // de colonnes plutôt qu'un string — hors périmètre de ce module
            // (cf. foreignKeyFor() ci-dessus, même règle pour les FK de
            // relation que pour les FK de schéma).
            if (! is_string($foreignKeyName)) {
                continue;
            }

            $relations[$foreignKeyName] = [
                'table' => $relation->getRelated()->getTable(),
                'column' => $relation->getOwnerKeyName(),
            ];
        }

        return $relations;
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

    /**
     * EX-450/EX-463 : `text`/`mediumtext`/`longtext` sur les trois drivers réellement
     * testés (mysql, sqlite, pgsql — ce dernier ne distinguant pas les
     * tailles, `mediumText()`/`longText()` y compilent tous deux en `text`) ;
     * `tinytext` (capacité comparable à `varchar`, 255 caractères) n'est
     * volontairement pas incluse.
     *
     * @param  array{type: string, type_name: string}  $column
     */
    private function isLongText(array $column): bool
    {
        return in_array(strtolower($column['type_name']), ['text', 'mediumtext', 'longtext'], true);
    }
}
