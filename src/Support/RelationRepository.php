<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Assemble le listing des relations Eloquent déclarées par le modèle hôte
 * (nom, type, multiplicité, modèle/connexion cible, navigabilité — EX-306 à
 * EX-309, EX-426) et le listing paginé des objets liés d'une relation donnée
 * (EX-427 à EX-431) — source unique consommée à la fois par le diagramme
 * Mermaid (module 3) et par les tableaux d'objets liés de la fiche détail
 * d'un item (module 4), pour ne pas dupliquer la logique de résolution.
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
     * @return array{data: array<int, array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}|null
     *
     * @throws RelationUnavailableException
     */
    public function paginateRelated(string $connection, string $model, string $itemId, string $relationName, int $page, int $perPage): ?array
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

        $paginator = $instance->{$relationName}()->paginate($perPage, ['*'], 'page', max(1, $page));

        return [
            'data' => collect($paginator->items())->map(fn (Model $row) => $row->getAttributes())->all(),
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
