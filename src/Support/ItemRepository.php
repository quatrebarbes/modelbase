<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Listing paginé (EX-401, EX-403), détail décoré par type de colonne (EX-405
 * à EX-410), schéma des colonnes (EX-412/EX-414/EX-415/EX-416) et écriture
 * (EX-412/EX-413/EX-417) des items d'un modèle. EX-402 (colonnes
 * « principales » du listing) est un point ouvert non tranché (cf.
 * docs/roadmap.md, Phase 5) : en attendant, le listing renvoie la valeur
 * brute de toutes les colonnes.
 */
class ItemRepository
{
    public function __construct(
        private ModelResolver $resolver,
        private EloquentModelFinder $models,
        private ColumnIntrospector $columns,
        private DatabaseErrorTranslator $errors,
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
        $technical = $this->technicalColumns($instance);

        $row = $db->table($table)->where($key, $id)->first();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;

        return [
            'id' => $row[$key],
            'values' => collect($this->columns->forTable($connection, $table))
                ->map(fn (array $column) => $this->decorate($db, $connection, $column, $row[$column['name']] ?? null, $technical))
                ->values()
                ->all(),
        ];
    }

    /**
     * EX-412/EX-414/EX-415/EX-416 : schéma des colonnes d'un modèle (type,
     * clé étrangère, caractère technique), sans valeur — utilisé par le
     * formulaire front pour se construire indépendamment de l'existence d'un
     * item (création, ou modèle encore vide, cf. EX-404).
     *
     * @return array<int, array<string, mixed>>
     */
    public function columns(string $connection, string $model): array
    {
        $class = $this->resolver->resolve($connection, $model);
        $instance = new $class;
        $technical = $this->technicalColumns($instance);

        return collect($this->columns->forTable($connection, $instance->getTable()))
            ->map(fn (array $column) => $this->describeColumn($connection, $column, $technical))
            ->values()
            ->all();
    }

    /**
     * EX-412/EX-417 : crée un item à partir des valeurs soumises. Les
     * colonnes techniques (EX-416) sont ignorées si elles sont soumises
     * malgré tout — gérées ici (horodatages) ou pas du tout modifiables (clé
     * primaire, auto-générée). Aucune validation propre au plug-in : les
     * valeurs sont écrites telles quelles, la contrainte de la colonne étant
     * appliquée par la BDD elle-même et sa violation éventuelle traduite par
     * DatabaseErrorTranslator.
     *
     * @param  array<string, mixed>  $values
     * @return array{id: mixed, values: array<int, array<string, mixed>>}
     *
     * @throws ItemValidationException
     */
    public function create(string $connection, string $model, array $values): array
    {
        $class = $this->resolver->resolve($connection, $model);
        $instance = new $class;
        $db = $instance->getConnection();
        $table = $instance->getTable();
        $columnDefinitions = $this->columns->forTable($connection, $table);
        $known = collect($columnDefinitions)->pluck('name')->all();

        $writable = $this->writable($values, $instance, $columnDefinitions);

        // Un modèle Eloquent a `$timestamps` à `true` par défaut, sans lien
        // garanti avec la présence réelle des colonnes created_at/updated_at
        // dans la table (ex. modèle sans `$table->timestamps()` déclaré) —
        // ne les renseigner que si elles existent réellement (cf. $known).
        if ($instance->usesTimestamps() && in_array($instance->getCreatedAtColumn(), $known, true)) {
            $now = $instance->freshTimestampString();
            $writable[$instance->getCreatedAtColumn()] = $now;
            $writable[$instance->getUpdatedAtColumn()] = $now;
        }

        try {
            $id = $db->table($table)->insertGetId($writable, $instance->getKeyName());
        } catch (QueryException $exception) {
            throw $this->toValidationException($exception, $db, $table);
        }

        return $this->find($connection, $model, (string) $id);
    }

    /**
     * EX-413/EX-417 : modifie les valeurs d'un item existant, même principe
     * que create() pour les colonnes techniques et l'absence de validation
     * propre au plug-in. Renvoie `null` si l'item n'existe pas (cf.
     * ItemController::update, qui traduit ce cas en 404).
     *
     * @param  array<string, mixed>  $values
     * @return array{id: mixed, values: array<int, array<string, mixed>>}|null
     *
     * @throws ItemValidationException
     */
    public function update(string $connection, string $model, string $id, array $values): ?array
    {
        $class = $this->resolver->resolve($connection, $model);
        $instance = new $class;
        $db = $instance->getConnection();
        $table = $instance->getTable();
        $key = $instance->getKeyName();

        if (! $db->table($table)->where($key, $id)->exists()) {
            return null;
        }

        $columnDefinitions = $this->columns->forTable($connection, $table);
        $known = collect($columnDefinitions)->pluck('name')->all();
        $writable = $this->writable($values, $instance, $columnDefinitions);

        if ($instance->usesTimestamps() && in_array($instance->getUpdatedAtColumn(), $known, true)) {
            $writable[$instance->getUpdatedAtColumn()] = $instance->freshTimestampString();
        }

        if ($writable !== []) {
            try {
                $db->table($table)->where($key, $id)->update($writable);
            } catch (QueryException $exception) {
                throw $this->toValidationException($exception, $db, $table);
            }
        }

        return $this->find($connection, $model, $id);
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
     * EX-416 : ne conserve, parmi les valeurs soumises, que celles
     * correspondant à une colonne réelle de la table et qui n'est pas une
     * colonne technique (clé primaire, horodatages) — ces dernières sont
     * gérées par ce repository (create) ou non modifiables du tout (update).
     * Encode en JSON la valeur d'une colonne de type JSON soumise sous forme
     * de tableau/objet (le cas normal pour un formulaire front, EX-414) : la
     * requête passe par le query builder brut plutôt que par `Model::save()`,
     * qui aurait fait cet encodage lui-même via le cast `json` d'Eloquent.
     *
     * @param  array<string, mixed>  $values
     * @param  array<int, array{name: string, type: string, is_foreign_key: bool, foreign_key: array{table: string, column: string}|null}>  $columnDefinitions
     * @return array<string, mixed>
     */
    private function writable(array $values, Model $instance, array $columnDefinitions): array
    {
        $known = collect($columnDefinitions)->pluck('name')->all();
        $technical = $this->technicalColumns($instance);
        $jsonColumns = collect($columnDefinitions)
            ->where('type', ColumnType::JSON->value)
            ->pluck('name')
            ->all();

        return collect($values)
            ->only($known)
            ->except($technical)
            ->map(fn ($value, $column) => in_array($column, $jsonColumns, true) && is_array($value)
                ? json_encode($value)
                : $value)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function technicalColumns(Model $instance): array
    {
        $technical = [$instance->getKeyName()];

        if ($instance->usesTimestamps()) {
            $technical[] = $instance->getCreatedAtColumn();
            $technical[] = $instance->getUpdatedAtColumn();
        }

        return $technical;
    }

    /**
     * @param  array{name: string, type: string, is_foreign_key: bool, foreign_key: array{table: string, column: string}|null}  $column
     * @param  array<int, string>  $technical
     */
    private function describeColumn(string $connection, array $column, array $technical): array
    {
        $described = [
            'column' => $column['name'],
            'type' => $column['type'],
            'technical' => in_array($column['name'], $technical, true), // EX-416
        ];

        if ($column['is_foreign_key']) {
            $model = $this->models->classForTable($connection, $column['foreign_key']['table']);

            $described['foreign_key'] = [
                'table' => $column['foreign_key']['table'],
                'model' => $model !== null ? class_basename($model) : null,
            ];
        }

        return $described;
    }

    /**
     * @param  array{name: string, type: string, is_foreign_key: bool, foreign_key: array{table: string, column: string}|null}  $column
     * @param  array<int, string>  $technical
     */
    private function decorate(Connection $db, string $connection, array $column, mixed $value, array $technical): array
    {
        $decorated = $this->describeColumn($connection, $column, $technical) + [
            'value' => $value,
            'is_null' => $value === null, // EX-409 : distinct d'une chaîne vide, qui reste ''
        ];

        if ($column['is_foreign_key']) {
            $decorated['foreign_key'] = $this->resolveForeignKey($db, $connection, $column['foreign_key'], $value);
        }

        return $decorated;
    }

    /**
     * EX-417 : traduit la QueryException (contrainte NOT NULL/UNIQUE/FK/
     * format violée) en ItemValidationException, avec un message lisible par
     * colonne — jamais une règle de validation décidée par le plug-in
     * lui-même, seulement la mise en forme du verdict du moteur de BDD (cf.
     * DatabaseErrorTranslator).
     */
    private function toValidationException(QueryException $exception, Connection $db, string $table): ItemValidationException
    {
        $translated = $this->errors->translate($exception, $db->getDriverName(), $table);
        $column = $translated['column'] ?? '_general';

        return new ItemValidationException([
            $column => [$this->friendlyMessage($translated['rule'], $translated['column'], $translated['message'])],
        ]);
    }

    private function friendlyMessage(string $rule, ?string $column, string $raw): string
    {
        return match ($rule) {
            'required' => $column !== null
                ? "La colonne « {$column} » est obligatoire."
                : 'Une colonne obligatoire est manquante.',
            'unique' => $column !== null
                ? "La valeur saisie pour « {$column} » est déjà utilisée."
                : 'Cette valeur est déjà utilisée.',
            'format' => $column !== null
                ? "La valeur saisie pour « {$column} » n'est pas valide."
                : "Une valeur saisie n'est pas valide.",
            'foreign_key' => $column !== null
                ? "La valeur saisie pour « {$column} » ne référence aucun enregistrement existant."
                : 'Une référence saisie est invalide.',
            default => $raw,
        };
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
