<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * EX-307 (diagramme de classe, module 3) / EX-425 (tableaux d'objets liés,
 * module 4) : détecte les relations Eloquent déclarées par le modèle hôte
 * (méthode publique sans paramètre requis, invoquée pour inspecter l'objet
 * Relation construit) — même mécanisme de réflexion que
 * ColumnIntrospector::relationForeignKeys() pour EX-423, étendu de BelongsTo
 * à HasMany/BelongsToMany/MorphMany/HasManyThrough/HasOne/MorphOne.
 * Construire une relation n'exécute aucune requête (`addConstraints()` ne
 * fait qu'ajouter une clause `where` au futur query builder), invoquer la
 * méthode de relation est donc sans effet de bord — sous réserve du même
 * garde-fou de sécurité que ColumnIntrospector (RelationMethodDenylist,
 * partagé pour ne jamais faire diverger les deux mécanismes).
 */
class RelationIntrospector
{
    /**
     * @var array<class-string<Relation<Model, Model, mixed>>, string>
     */
    private const TYPES = [
        BelongsTo::class => 'one',
        HasOne::class => 'one',
        MorphOne::class => 'one',
        HasMany::class => 'many',
        BelongsToMany::class => 'many',
        MorphMany::class => 'many',
        HasManyThrough::class => 'many',
    ];

    /**
     * @return array<string, array{type: string, multiplicity: string, related: Model}>
     */
    public function relationsOf(Model $instance): array
    {
        $denylist = RelationMethodDenylist::get();
        $relations = [];

        foreach ((new ReflectionClass($instance))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (
                $method->isStatic()
                || $method->getNumberOfRequiredParameters() > 0
                || in_array($method->getName(), $denylist, true)
            ) {
                continue;
            }

            try {
                $relation = $method->invoke($instance);
            } catch (Throwable) {
                continue;
            }

            $type = $this->matchedType($relation);

            if ($type === null) {
                continue;
            }

            $relations[$method->getName()] = [
                'type' => class_basename($type),
                'multiplicity' => self::TYPES[$type],
                'related' => $relation->getRelated(),
            ];
        }

        return $relations;
    }

    /**
     * @return class-string<Relation<Model, Model, mixed>>|null
     */
    private function matchedType(mixed $relation): ?string
    {
        if (! $relation instanceof Relation) {
            return null;
        }

        foreach (self::TYPES as $class => $multiplicity) {
            if ($relation instanceof $class) {
                return $class;
            }
        }

        return null;
    }
}
