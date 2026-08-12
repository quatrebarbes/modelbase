<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Assemble le listing des modèles Eloquent déclarés par l'application hôte
 * pour une connexion donnée (EX-301), avec nom, nombre d'items (EX-302,
 * approché pour une grande table, EX-312) et nombre de propriétés réellement
 * exposées par le modèle (EX-302/EX-313 : `$fillable` ∪ attributs castés ∪
 * colonnes techniques ∪ clés étrangères de relation déclarée — même allowlist
 * qu'`ItemRepository::columnsFor()` pour le module 4, EX-422, cf.
 * `ColumnIntrospector::exposedColumnNames()`), filtrable par nom ou par nom de
 * table (EX-304). Deux classes Eloquent déclarées pointant vers la même table
 * donnent deux entrées distinctes : le listing est construit par classe,
 * jamais dédupliqué par table.
 *
 * `table_exists` signale au front qu'un modèle référence une table absente
 * de la base, pour qu'il l'affiche comme non navigable plutôt que comme une
 * table vide (limite EX-303/EX-305 du module 3).
 */
class ModelRepository
{
    /**
     * En-deçà de ce nombre, un comptage exact reste peu coûteux : on ignore
     * l'estimation moteur (EX-312) et on compte réellement, pour ne jamais
     * afficher une valeur fantaisiste sur une table de petite taille (le cas
     * le plus courant).
     */
    private const EXACT_COUNT_THRESHOLD = 1_000;

    public function __construct(
        private EloquentModelFinder $models,
        private ItemCountEstimator $itemCounts,
        private ColumnIntrospector $columns,
    ) {
    }

    /**
     * @return array<int, array{name: string, table: string, table_exists: bool, item_count: string, item_count_raw: int, property_count: int}>
     */
    public function forConnection(string $connection, ?string $search = null): array
    {
        $classes = $this->models->forConnection($connection);

        // Une seule requête pour l'ensemble des modèles de la connexion,
        // plutôt qu'un hasTable() par modèle (N+1 corrigé en Phase 17).
        $existingTables = $classes === [] ? [] : $this->existingTables($connection);

        return collect($classes)
            ->map(fn (string $class) => $this->describe($class, $existingTables))
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
     * @return array<string, true> table name (minuscule) => true
     */
    private function existingTables(string $connection): array
    {
        $tables = DB::connection($connection)->getSchemaBuilder()->getTableListing(schemaQualified: false);

        return array_fill_keys(array_map('strtolower', $tables), true);
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class
     * @param  array<string, true>  $existingTables
     * @return array{name: string, table: string, table_exists: bool, item_count: string, item_count_raw: int, property_count: int}
     */
    private function describe(string $class, array $existingTables): array
    {
        $instance = new $class;
        $table = $instance->getTable();
        $connection = $instance->getConnection();
        // Même nom de connexion que celui utilisé par EloquentModelFinder pour
        // filtrer les modèles de cette connexion (cf. classForTable()) —
        // nécessaire pour ColumnIntrospector::exposedColumnNames(), qui
        // interroge le schéma via Schema::connection($name).
        $connectionName = $instance->getConnectionName() ?? config('database.default');

        // Un modèle Eloquent déclaré peut ne correspondre à aucune table
        // réelle (ex. table pas encore migrée en prod) : on l'affiche quand
        // même (EX-301/EX-305 lisent le code, pas la base) avec des
        // compteurs à 0 plutôt que de planter le listing entier. `table`
        // garde le nom réel déclaré par le modèle pour que le filtre EX-304
        // continue de fonctionner ; `table_exists` permet au front de
        // signaler l'absence plutôt que d'afficher un nom de table trompeur.
        if (! isset($existingTables[strtolower($table)])) {
            return [
                'name' => class_basename($class),
                'table' => $table,
                'table_exists' => false,
                'item_count' => ApproximateCount::format(0),
                'item_count_raw' => 0,
                'property_count' => 0,
            ];
        }

        $itemCount = $this->itemCount($connection, $table);

        return [
            'name' => class_basename($class),
            'table' => $table,
            'table_exists' => true,
            // Valeur numérique brute, en plus du formatage EX-312, pour un tri
            // (EX-313/EX-316) cohérent avec la valeur affichée : `item_count`
            // (ex. "1.2K") ne s'ordonne pas correctement lexicographiquement.
            'item_count' => ApproximateCount::format($itemCount),
            'item_count_raw' => $itemCount,
            // EX-302/EX-313 : nombre de propriétés réellement exposées par le
            // modèle (allowlist EX-422), pas le nombre brut de colonnes de la
            // table — un modèle sans $fillable/cast/relation belongsTo ne
            // compte donc que ses colonnes techniques (avertissement SFD).
            'property_count' => count($this->columns->exposedColumnNames($connectionName, $instance)),
        ];
    }

    private function itemCount(Connection $connection, string $table): int
    {
        $estimate = $this->itemCounts->estimate($connection, $table);

        if ($estimate !== null && $estimate >= self::EXACT_COUNT_THRESHOLD) {
            return $estimate;
        }

        return $connection->table($table)->count();
    }
}
