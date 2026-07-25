<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Listing paginé (EX-401, EX-403), détail décoré par type de colonne (EX-405
 * à EX-410), schéma des colonnes (EX-412/EX-414/EX-415/EX-416/EX-421/EX-422/
 * EX-423) et écriture (EX-412/EX-413/EX-417) des items d'un modèle. EX-402
 * (colonnes « principales » du listing) est un point ouvert non tranché (cf.
 * docs/roadmap.md, Phase 6) : en attendant, le listing renvoie la valeur
 * brute de toutes les colonnes exposées (cf. `columnsFor()`, EX-422).
 *
 * Écriture (create/update/delete) via une instance Eloquent réelle du modèle
 * hôte (fill()/save()/delete()) plutôt que le query builder brut utilisé
 * jusqu'en Phase 4c, pour déclencher les événements du modèle hôte
 * (creating/created, updating/updated, deleting/deleted — EX-424).
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
            'values' => collect($this->columnsFor($connection, $instance))
                ->map(fn (array $column) => $this->decorate($db, $connection, $column, $row[$column['name']] ?? null, $technical, $instance))
                ->values()
                ->all(),
        ];
    }

    /**
     * EX-412/EX-414/EX-415/EX-416/EX-421 : schéma des colonnes d'un modèle
     * (type, clé étrangère, caractère technique, caractère fillable), sans
     * valeur — utilisé par le formulaire front pour se construire
     * indépendamment de l'existence d'un item (création, ou modèle encore
     * vide, cf. EX-404).
     *
     * @return array<int, array<string, mixed>>
     */
    public function columns(string $connection, string $model): array
    {
        $class = $this->resolver->resolve($connection, $model);
        $instance = new $class;
        $technical = $this->technicalColumns($instance);

        return collect($this->columnsFor($connection, $instance))
            ->map(fn (array $column) => $this->describeColumn($connection, $column, $technical, $instance))
            ->values()
            ->all();
    }

    /**
     * EX-424/EX-412/EX-417/EX-421 : crée un item à partir des valeurs
     * soumises, via une instance Eloquent réelle du modèle hôte
     * (fill()+save()) pour déclencher creating/created. Les colonnes
     * techniques (EX-416) et non fillable (EX-421) sont ignorées si elles
     * sont soumises malgré tout — les horodatages restent gérés
     * nativement par Eloquent (cf. disableTimestampsIfColumnsMissing()) ;
     * seule la clé primaire n'est jamais modifiable. Aucune validation
     * propre au plug-in : les valeurs sont écrites telles quelles, la
     * contrainte de la colonne étant appliquée par la BDD elle-même et sa
     * violation éventuelle traduite par DatabaseErrorTranslator.
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
        $table = $instance->getTable();
        $columnDefinitions = $this->columnsFor($connection, $instance);
        $known = collect($columnDefinitions)->pluck('name')->all();

        $this->disableTimestampsIfColumnsMissing($instance, $known);
        $instance->fill($this->writable($values, $instance, $columnDefinitions));

        try {
            $instance->save();
        } catch (QueryException $exception) {
            throw $this->toValidationException($exception, $instance->getConnection(), $table);
        }

        return $this->find($connection, $model, (string) $instance->getKey());
    }

    /**
     * EX-424/EX-413/EX-417/EX-421 : modifie les valeurs d'un item existant,
     * via l'instance Eloquent résolue (fill()+save()) pour déclencher
     * updating/updated — même principe que create() pour les colonnes
     * techniques/non fillable et l'absence de validation propre au plug-in.
     * Renvoie `null` si l'item n'existe pas (cf. ItemController::update, qui
     * traduit ce cas en 404).
     *
     * @param  array<string, mixed>  $values
     * @return array{id: mixed, values: array<int, array<string, mixed>>}|null
     *
     * @throws ItemValidationException
     */
    public function update(string $connection, string $model, string $id, array $values): ?array
    {
        $class = $this->resolver->resolve($connection, $model);
        $instance = $class::find($id);

        if ($instance === null) {
            return null;
        }

        $table = $instance->getTable();
        $columnDefinitions = $this->columnsFor($connection, $instance);
        $known = collect($columnDefinitions)->pluck('name')->all();

        $this->disableTimestampsIfColumnsMissing($instance, $known);
        $instance->fill($this->writable($values, $instance, $columnDefinitions));

        try {
            $instance->save();
        } catch (QueryException $exception) {
            throw $this->toValidationException($exception, $instance->getConnection(), $table);
        }

        return $this->find($connection, $model, $id);
    }

    /**
     * EX-424/EX-418/EX-420 : supprime un item existant via l'instance
     * Eloquent résolue (delete()) pour déclencher deleting/deleted. Renvoie
     * `false` si l'item n'existe pas (cf. ItemController::destroy, qui
     * traduit ce cas en 404). Aucune suppression forcée (EX-420) : si l'item
     * est encore référencé par une clé étrangère d'un autre enregistrement,
     * la QueryException levée par le moteur de BDD est traduite en
     * ItemDeletionException plutôt que d'être contournée (ex. suppression en
     * cascade).
     *
     * @throws ItemDeletionException
     */
    public function delete(string $connection, string $model, string $id): bool
    {
        $class = $this->resolver->resolve($connection, $model);
        $instance = $class::find($id);

        if ($instance === null) {
            return false;
        }

        try {
            $instance->delete();
        } catch (QueryException $exception) {
            throw $this->toDeletionException($exception, $instance->getConnection(), $instance->getTable());
        }

        return true;
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
     * EX-422 : restreint les colonnes exposées à celles réellement connues du
     * code du modèle hôte — `$fillable`, attributs castés (`$casts`/
     * `casts()`), colonnes techniques (clé primaire, timestamps, déjà gérées
     * par `technicalColumns()`) et clés étrangères déclarées via une relation
     * Eloquent (EX-423, ci-dessous) — le schéma de la base ne fournissant
     * plus que le type de chacune (`ColumnIntrospector::forTable()`, resté
     * inchangé). Une colonne de la table absente de ces quatre sources
     * (jamais fillable, jamais castée, ni technique, ni clé étrangère
     * déclarée) disparaît donc entièrement du listing/de la fiche détail/du
     * formulaire, plutôt que d'être seulement affichée en lecture seule
     * (EX-421/EX-416) : lecture assumée d'EX-422 après clarification, la
     * tension avec EX-401/EX-406 (« toutes les colonnes ») étant résolue en
     * faveur de la fidélité au code hôte.
     *
     * EX-423 : une clé étrangère déclarée via une relation Eloquent
     * (`belongsTo`) prévaut sur la seule contrainte FK de la base — prise en
     * compte même sans contrainte réelle en base, sa cible (table/colonne
     * référencée) étant celle de la relation plutôt que celle de la
     * contrainte quand les deux existent (cf. `ColumnIntrospector::
     * relationForeignKeys()`).
     *
     * @return array<int, array{name: string, type: string, is_foreign_key: bool, foreign_key: array{table: string, column: string}|null}>
     */
    private function columnsFor(string $connection, Model $instance): array
    {
        $relations = $this->columns->relationForeignKeys($instance);
        $exposed = array_unique(array_merge(
            $instance->getFillable(),
            array_keys($instance->getCasts()),
            $this->technicalColumns($instance),
            array_keys($relations),
        ));

        return collect($this->columns->forTable($connection, $instance->getTable()))
            ->filter(fn (array $column) => in_array($column['name'], $exposed, true))
            ->map(function (array $column) use ($relations) {
                if (! isset($relations[$column['name']])) {
                    return $column;
                }

                return [
                    ...$column,
                    'type' => ColumnType::FOREIGN_KEY->value,
                    'is_foreign_key' => true,
                    'foreign_key' => $relations[$column['name']],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * EX-416/EX-421 : ne conserve, parmi les valeurs soumises, que celles
     * correspondant à une colonne réelle de la table, qui n'est pas une
     * colonne technique (clé primaire, horodatages — gérées par ce
     * repository ou non modifiables du tout) et que le modèle hôte autorise
     * en mass assignment (`$fillable`/`$guarded`, EX-421). Encode en JSON la
     * valeur d'une colonne de type JSON soumise sous forme de tableau/objet
     * (le cas normal pour un formulaire front, EX-414), sauf si le modèle
     * hôte déclare déjà un cast pour cette colonne (`array`/`json`/...) : le
     * cast d'Eloquent s'en chargera lui-même à l'écriture, un encodage
     * préalable produirait une valeur doublement encodée.
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
            ->filter(fn ($value, $column) => $instance->isFillable($column))
            ->map(fn ($value, $column) => in_array($column, $jsonColumns, true) && is_array($value) && ! $instance->hasCast($column)
                ? json_encode($value)
                : $value)
            ->all();
    }

    /**
     * Un modèle Eloquent a `$timestamps` à `true` par défaut, sans lien
     * garanti avec la présence réelle des colonnes created_at/updated_at dans
     * la table (ex. modèle sans `$table->timestamps()` déclaré) — Eloquent
     * lèverait une erreur SQL en tentant de les renseigner à l'écriture si
     * elles n'existent pas réellement (cf. $known).
     *
     * @param  array<int, string>  $known
     */
    private function disableTimestampsIfColumnsMissing(Model $instance, array $known): void
    {
        if (
            $instance->usesTimestamps()
            && (! in_array($instance->getCreatedAtColumn(), $known, true) || ! in_array($instance->getUpdatedAtColumn(), $known, true))
        ) {
            $instance->timestamps = false;
        }
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
    private function describeColumn(string $connection, array $column, array $technical, Model $instance): array
    {
        $described = [
            'column' => $column['name'],
            'type' => $column['type'],
            'technical' => in_array($column['name'], $technical, true), // EX-416
            'fillable' => $instance->isFillable($column['name']), // EX-421
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
    private function decorate(Connection $db, string $connection, array $column, mixed $value, array $technical, Model $instance): array
    {
        $decorated = $this->describeColumn($connection, $column, $technical, $instance) + [
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

    /**
     * EX-420 : traduit la QueryException levée par une suppression bloquée
     * par une contrainte de clé étrangère entrante (un autre enregistrement
     * référence encore cet item) en ItemDeletionException. Contrairement à
     * toValidationException(), la colonne fautive appartient à la table qui
     * référence l'item supprimé, pas à l'item lui-même — seul le message brut
     * du moteur de BDD est donc affiché (EX-420 : « affiche ... l'erreur
     * d'intégrité référentielle renvoyée par la base de données »), plutôt
     * qu'un message reformulé par colonne comme pour la validation.
     */
    private function toDeletionException(QueryException $exception, Connection $db, string $table): ItemDeletionException
    {
        $translated = $this->errors->translate($exception, $db->getDriverName(), $table);

        if ($translated['rule'] !== 'foreign_key') {
            throw $exception;
        }

        return new ItemDeletionException(
            "Suppression impossible : cet item est encore référencé par d'autres enregistrements ({$translated['message']})."
        );
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
