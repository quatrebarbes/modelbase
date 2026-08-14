<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
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
     * EX-302/EX-313/EX-422 : colonnes techniques d'un modèle (clé primaire,
     * timestamps, colonne de suppression douce) — communes au calcul des
     * propriétés exposées par un modèle (`exposedColumnNames()` ci-dessous,
     * module 3) et à celui des colonnes exposées avec leur type/décoration
     * (`ItemRepository::columnsFor()`, module 4).
     *
     * @return array<int, string>
     */
    public function technicalColumns(Model $instance): array
    {
        $technical = [$instance->getKeyName()];

        if ($instance->usesTimestamps()) {
            $technical[] = $instance->getCreatedAtColumn();
            $technical[] = $instance->getUpdatedAtColumn();
        }

        if (ModelTraitInspector::uses($instance, SoftDeletes::class)) {
            $technical[] = $instance->getDeletedAtColumn();
        }

        return $technical;
    }

    /**
     * EX-422 : colonnes réellement exposées par un modèle — restreint les
     * colonnes réelles de sa table (`forTable()`) à celles appartenant à
     * l'allowlist `$fillable` ∪ attributs castés (`getCasts()`) ∪ colonnes
     * techniques ∪ clés étrangères déclarées via une relation Eloquent
     * (EX-423) — le schéma de la base ne fournissant plus que le type de
     * chacune. Une colonne de la table absente de ces quatre sources (jamais
     * fillable, jamais castée, ni technique, ni clé étrangère déclarée)
     * disparaît donc entièrement, plutôt que d'être seulement affichée en
     * lecture seule (EX-464/EX-416). Extraite de `ItemRepository::
     * columnsFor()` (module 4) en Phase 24 pour être réutilisée telle quelle
     * par `Support\ModelRepository` (nombre de propriétés du listing des
     * modèles, EX-302/EX-313, module 3) sans dupliquer cette logique.
     *
     * @return array<int, array{name: string, type: string, is_foreign_key: bool, foreign_key: array{table: string, column: string}|null, long: bool}>
     */
    public function exposedColumns(string $connection, Model $instance): array
    {
        $relations = $this->relationForeignKeys($instance);
        $allowlist = $this->allowlistedColumnNames($instance, $relations);
        $casts = $instance->getCasts();

        return collect($this->forTable($connection, $instance->getTable()))
            ->filter(fn (array $column) => in_array($column['name'], $allowlist, true))
            ->map(function (array $column) use ($relations, $casts) {
                if (isset($relations[$column['name']])) {
                    return [
                        ...$column,
                        'type' => ColumnType::FOREIGN_KEY->value,
                        'is_foreign_key' => true,
                        'foreign_key' => $relations[$column['name']],
                    ];
                }

                return $this->applyCastType($column, $casts[$column['name']] ?? null);
            })
            ->values()
            ->all();
    }

    /**
     * EX-474 : le type de rendu (EX-407) d'une colonne castée suit en
     * priorité une table de correspondance cast Eloquent → type, avec repli
     * explicite sur le type déjà déduit du schéma de la base (`$column['type']`,
     * calculé par `scalarType()` au sein de `forTable()`) : cast non reconnu
     * (classe de cast personnalisée, énumération, `AsCollection` et
     * assimilés) ou colonne détectée comme clé étrangère (prioritaire sur tout
     * cast scalaire, cf. `exposedColumns()` ci-dessus). Le caractère « texte
     * long » (`long`, EX-450/EX-463) n'est en revanche jamais remis en cause
     * par un cast — aucun cast Eloquent ne porte cette information — même
     * lorsque le cast fait par ailleurs suivre le type de rendu.
     *
     * @param  array{name: string, type: string, is_foreign_key: bool, foreign_key: array{table: string, column: string}|null, long: bool}  $column
     * @return array{name: string, type: string, is_foreign_key: bool, foreign_key: array{table: string, column: string}|null, long: bool}
     */
    private function applyCastType(array $column, ?string $cast): array
    {
        if ($column['is_foreign_key'] || $cast === null) {
            return $column;
        }

        $type = $this->castType($cast);

        if ($type === null) {
            return $column;
        }

        return [...$column, 'type' => $type->value];
    }

    /**
     * EX-474 : correspondance cast Eloquent → type de rendu. `null` signale un
     * cast non couvert par cette correspondance (classe de cast personnalisée
     * implémentant `CastsAttributes`, cast d'énumération, `AsCollection` et
     * assimilés, ou toute valeur de cast non reconnue) — à charge de l'appelant
     * de replier sur le type déduit du schéma dans ce cas (cf. `applyCastType()`).
     */
    private function castType(string $cast): ?ColumnType
    {
        $normalized = strtolower($cast);
        [$base] = explode(':', $normalized, 2);

        return match (true) {
            in_array($normalized, ['boolean', 'bool'], true) => ColumnType::BOOLEAN,
            in_array($base, ['integer', 'int', 'real', 'float', 'double', 'decimal'], true) => ColumnType::NUMBER,
            in_array($normalized, ['date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp'], true)
                || $base === 'custom_datetime' => ColumnType::DATE,
            in_array($normalized, [
                'array', 'json', 'collection', 'object',
                'encrypted:array', 'encrypted:json', 'encrypted:collection', 'encrypted:object',
            ], true) => ColumnType::JSON,
            in_array($normalized, ['string', 'encrypted'], true) => ColumnType::STRING,
            default => null,
        };
    }

    /**
     * EX-302/EX-313 (module 3) : ne renvoie que les noms de `exposedColumns()`
     * ci-dessus — `Support\ModelRepository` n'a besoin que du compte, pas du
     * détail décoré (type/FK) que renvoie `exposedColumns()`.
     *
     * @return array<int, string>
     */
    public function exposedColumnNames(string $connection, Model $instance): array
    {
        return array_column($this->exposedColumns($connection, $instance), 'name');
    }

    /**
     * Allowlist des noms de colonnes qu'un modèle expose, avant intersection
     * avec les colonnes réellement présentes dans le schéma de sa table (cf.
     * `exposedColumns()` ci-dessus) — un nom de `$fillable`/cast qui ne
     * correspondrait à aucune colonne réelle (typo, attribut virtuel) ne doit
     * jamais être compté ni affiché, d'où cette intersection systématique.
     *
     * @param  array<string, array{table: string, column: string}>  $relations
     * @return array<int, string>
     */
    private function allowlistedColumnNames(Model $instance, array $relations): array
    {
        return array_unique(array_merge(
            $instance->getFillable(),
            array_keys($instance->getCasts()),
            $this->technicalColumns($instance),
            array_keys($relations),
        ));
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
