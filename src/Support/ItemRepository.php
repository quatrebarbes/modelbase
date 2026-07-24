<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Connection;

/**
 * Listing paginé (EX-401, EX-403) et détail décoré par type de colonne
 * (EX-405 à EX-410) des items d'un modèle. EX-402 (colonnes « principales »
 * du listing) est un point ouvert non tranché (cf. docs/roadmap.md, Phase 5) :
 * en attendant, le listing renvoie la valeur brute de toutes les colonnes.
 */
class ItemRepository
{
    public function __construct(
        private ModelResolver $resolver,
        private EloquentModelFinder $models,
        private ColumnIntrospector $columns,
    ) {
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function paginate(string $connection, string $model, int $page, int $perPage): array
    {
        [$db, $table] = $this->tableFor($connection, $model);

        $paginator = $db->table($table)->paginate($perPage, ['*'], 'page', max(1, $page));

        return [
            'data' => collect($paginator->items())->map(fn ($row) => (array) $row)->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array{id: mixed, values: array<int, array<string, mixed>>}|null
     */
    public function find(string $connection, string $model, string $id): ?array
    {
        $class = $this->resolver->resolve($connection, $model);
        $instance = new $class;
        $db = $instance->getConnection();
        $table = $instance->getTable();
        $key = $instance->getKeyName();

        $row = $db->table($table)->where($key, $id)->first();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;

        return [
            'id' => $row[$key],
            'values' => collect($this->columns->forTable($connection, $table))
                ->map(fn (array $column) => $this->decorate($db, $connection, $column, $row[$column['name']] ?? null))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{0: Connection, 1: string}
     */
    private function tableFor(string $connection, string $model): array
    {
        $class = $this->resolver->resolve($connection, $model);
        $instance = new $class;

        return [$instance->getConnection(), $instance->getTable()];
    }

    /**
     * @param  array{name: string, type: string, is_foreign_key: bool, foreign_key: array{table: string, column: string}|null}  $column
     */
    private function decorate(Connection $db, string $connection, array $column, mixed $value): array
    {
        $decorated = [
            'column' => $column['name'],
            'type' => $column['type'],
            'value' => $value,
            'is_null' => $value === null, // EX-409 : distinct d'une chaîne vide, qui reste ''
        ];

        if ($column['is_foreign_key']) {
            $decorated['foreign_key'] = $this->resolveForeignKey($db, $connection, $column['foreign_key'], $value);
        }

        return $decorated;
    }

    /**
     * EX-408/EX-410 : la clé étrangère référence un item dans son propre
     * modèle — recherché parmi les modèles Eloquent déclarés pour la même
     * connexion (les clés étrangères inter-connexions ne sont pas gérées par
     * ce module, cf. limites du module 3 sur les connexions). `navigable` est
     * faux si le modèle référencé n'est pas déclaré ou si l'item référencé
     * n'existe pas/plus.
     *
     * @param  array{table: string, column: string}  $foreignKey
     */
    private function resolveForeignKey(Connection $db, string $connection, array $foreignKey, mixed $value): array
    {
        $model = $this->models->classForTable($connection, $foreignKey['table']);

        $exists = $value !== null
            && $model !== null
            && $db->table($foreignKey['table'])->where($foreignKey['column'], $value)->exists();

        return [
            'table' => $foreignKey['table'],
            'model' => $model !== null ? class_basename($model) : null,
            'navigable' => $value !== null && $exists,
        ];
    }
}
