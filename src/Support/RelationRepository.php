<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Assemble le listing des relations Eloquent déclarées par le modèle hôte
 * (nom, type, multiplicité, modèle/connexion cible, navigabilité — EX-306 à
 * EX-309, EX-426) et le listing paginé des objets liés d'une relation donnée
 * (EX-427 à EX-431) — source unique consommée à la fois par le diagramme de
 * relations (module 3, EX-310) et par les tableaux d'objets liés de la fiche
 * détail d'un item (module 4), pour ne pas dupliquer la logique de résolution.
 *
 * EX-308/EX-431 : la navigabilité d'une relation dépend des deux mêmes
 * conditions qu'une clé étrangère simple (EX-410) — modèle cible déclaré
 * (ModelResolver, pour que l'URL `/connections/{c}/models/{m}/items/{id}`
 * résolue côté front ne heurte pas EnsureModelIsNavigable) et connexion
 * cible disponible (ConnectionAvailability, revérifiée à chaud comme pour
 * toute connexion, EX-204/EX-208 — jamais mise en cache).
 */
class RelationRepository
{
    public function __construct(
        private ModelResolver $resolver,
        private RelationIntrospector $relations,
        private ConnectionAvailability $availability,
        private ItemRepository $items,
        private ItemQueryFilter $queryFilter,
    ) {
    }

    /**
     * @return array<int, array{name: string, type: string, multiplicity: string, related_model: string, related_connection: string, related_table: string, navigable: bool}>
     */
    public function forModel(string $connection, string $model): array
    {
        $instance = $this->blankInstance($connection, $model);

        if ($instance === null) {
            return [];
        }

        return collect($this->relations->relationsOf($instance))
            ->map(fn (array $relation, string $name) => $this->describe($connection, $name, $relation))
            ->values()
            ->all();
    }

    /**
     * EX-427/EX-428/EX-429/EX-430 : listing paginé des objets liés d'une
     * relation déclarée par l'item {itemId} du modèle {model} — `belongsTo`
     * n'est jamais exposée ici (`null`, traduit en 404 par le contrôleur) :
     * déjà couverte par la valeur de colonne de clé étrangère (EX-408/
     * EX-410), pas par un tableau distinct au sens d'EX-425.
     *
     * EX-470/EX-472/EX-473 : `filters`/`sort` (même forme que
     * `ItemRepository::paginate()`) sont restreints aux colonnes exposées par
     * le modèle lié (`ItemRepository::columnTypesFor()`, EX-422) — les
     * attributs de la table pivot d'une relation belongsToMany n'en font pas
     * partie, jamais exposés ici. Jamais évalués si la relation n'est pas
     * navigable (RelationUnavailableException levée avant, EX-473).
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}|null
     *
     * @throws RelationUnavailableException
     * @throws ItemFilterException
     */
    public function paginateRelated(string $connection, string $model, string $itemId, string $relationName, int $page, int $perPage, array $filters = [], ?string $sort = null): ?array
    {
        $instance = $this->findInstance($connection, $model, $itemId);

        if ($instance === null) {
            return null;
        }

        $relation = $this->relations->relationsOf($instance)[$relationName] ?? null;

        if ($relation === null || $relation['type'] === 'BelongsTo') {
            return null;
        }

        $descriptor = $this->describe($connection, $relationName, $relation);

        if (! $descriptor['navigable']) {
            throw new RelationUnavailableException(
                "Connexion « {$descriptor['related_connection']} » indisponible."
            );
        }

        // EX-470 : query builder brut (même pattern que Phase 12 pour
        // SoftDeletes, cf. ItemRepository::paginate()) pour appliquer
        // ItemQueryFilter::applyFilters()/applySort() — les contraintes
        // propres à la relation (clé étrangère, jointure pivot pour
        // belongsToMany) sont déjà posées par Eloquent à la construction de
        // `$relationQuery` et préservées par toBase().
        $query = $instance->{$relationName}()->toBase();

        // EX-427 : « même logique d'aperçu que le listing standard » (EX-402)
        // — la ligne renvoyée pour chaque objet lié est restreinte aux
        // colonnes exposées par le modèle lié, jamais la ligne brute complète
        // de sa table.
        $columnDefinitions = $this->items->columnsFor($descriptor['related_connection'], $relation['related']);
        $columnNames = collect($columnDefinitions)->pluck('name')->all();

        if ($columnNames !== []) {
            $query->select($columnNames);
        }

        if ($filters !== [] || ($sort !== null && $sort !== '')) {
            $columnTypes = collect($columnDefinitions)
                ->mapWithKeys(fn (array $column) => [$column['name'] => ColumnType::from($column['type'])])
                ->all();

            if ($filters !== []) {
                $this->queryFilter->applyFilters($query, $filters, $columnTypes);
            }

            if ($sort !== null && $sort !== '') {
                $this->queryFilter->applySort($query, $sort, $columnTypes);
            }
        }

        // EX-454 : même repli sur la première/dernière page qu'ItemRepository::paginate().
        $lastPage = max(1, (int) ceil($query->getCountForPagination() / $perPage));
        $currentPage = min(max($page, 1), $lastPage);

        $paginator = $query->paginate($perPage, ['*'], 'page', $currentPage);

        return [
            'data' => collect($paginator->items())->map(fn ($row) => (array) $row)->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                // EX-428 : nom réel de la colonne de clé primaire du modèle
                // lié — même besoin que ItemRepository::paginate() pour
                // qu'une clé primaire non nommée `id` reste navigable depuis
                // un tableau d'objets liés.
                'primary_key' => $relation['related']->getKeyName(),
            ],
        ];
    }

    private function blankInstance(string $connection, string $model): ?Model
    {
        $class = $this->resolver->resolve($connection, $model);

        return $class === null ? null : new $class;
    }

    private function findInstance(string $connection, string $model, string $id): ?Model
    {
        $class = $this->resolver->resolve($connection, $model);

        return $class === null ? null : $class::find($id);
    }

    /**
     * @param  array{type: string, multiplicity: string, related: Model}  $relation
     * @return array{name: string, type: string, multiplicity: string, related_model: string, related_connection: string, related_table: string, navigable: bool}
     */
    private function describe(string $connection, string $name, array $relation): array
    {
        $related = $relation['related'];
        $relatedConnection = $related->getConnectionName() ?? config('database.default');
        $relatedModel = class_basename($related);

        // Une relation ciblant la connexion courante n'a pas besoin d'être
        // revérifiée : sa disponibilité vient d'être établie par
        // EnsureConnectionIsNavigable pour servir cette requête. Revérifier
        // à chaud purgerait (ConnectionAvailability, EX-204/EX-208) une
        // connexion qu'on est sur le point d'utiliser nous-même — inutile en
        // conditions réelles, destructeur pour une connexion sqlite
        // ':memory:' (reconnexion vers une base vierge).
        $available = $relatedConnection === $connection || $this->availability->isAvailable($relatedConnection);

        return [
            'name' => $name,
            'type' => $relation['type'],
            'multiplicity' => $relation['multiplicity'],
            'related_model' => $relatedModel,
            'related_connection' => $relatedConnection,
            'related_table' => $related->getTable(),
            'navigable' => $available && $this->resolver->resolve($relatedConnection, $relatedModel) !== null,
        ];
    }
}
