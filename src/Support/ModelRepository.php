<?php

namespace Quatrebarbes\Modelbase\Support;

/**
 * Assemble le listing des modèles Eloquent déclarés par l'application hôte
 * pour une connexion donnée (EX-301), avec nom, nombre d'items et nombre de
 * colonnes de la table associée (EX-302), filtrable par nom ou par nom de
 * table (EX-304). Deux classes Eloquent déclarées pointant vers la même
 * table donnent deux entrées distinctes : le listing est construit par
 * classe, jamais dédupliqué par table.
 */
class ModelRepository
{
    public function __construct(private EloquentModelFinder $models)
    {
    }

    /**
     * @return array<int, array{name: string, table: string, item_count: int, column_count: int}>
     */
    public function forConnection(string $connection, ?string $search = null): array
    {
        return collect($this->models->forConnection($connection))
            ->map(fn (string $class) => $this->describe($class))
            ->when(
                $search !== null && $search !== '',
                fn ($models) => $models->filter(
                    fn (array $model) => str_contains(strtolower($model['name']), strtolower($search))
                        || str_contains(strtolower($model['table']), strtolower($search))
                )
            )
            ->values()
            ->all();
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class
     * @return array{name: string, table: string, item_count: int, column_count: int}
     */
    private function describe(string $class): array
    {
        $instance = new $class;
        $table = $instance->getTable();
        $connection = $instance->getConnection();

        return [
            'name' => class_basename($class),
            'table' => $table,
            'item_count' => $connection->table($table)->count(),
            'column_count' => count($connection->getSchemaBuilder()->getColumnListing($table)),
        ];
    }
}
