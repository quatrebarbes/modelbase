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
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;

/**
 * EX-307 (diagramme de relations, module 3) / EX-425 (tableaux d'objets liés,
 * module 4) : détecte les relations Eloquent déclarées par le modèle hôte
 * (méthode publique sans paramètre requis, invoquée pour inspecter l'objet
 * Relation construit) — même mécanisme de réflexion que
 * ColumnIntrospector::relationForeignKeys() pour EX-423, étendu de BelongsTo
 * à HasMany/BelongsToMany/MorphMany/HasManyThrough/HasOne/MorphOne.
 * Construire une relation n'exécute aucune requête (`addConstraints()` ne
 * fait qu'ajouter une clause `where` au futur query builder), invoquer la
 * méthode de relation est donc sans effet de bord — sous réserve du même
 * garde-fou de sécurité que ColumnIntrospector (RelationMethodGuard, partagé
 * pour ne jamais faire diverger les deux mécanismes). L'invocation elle-même
 * passe par `RelationMethodGuard::invoke()`, sous `Connection::pretend()` :
 * si la méthode invoquée n'est en réalité pas une relation mais exécute
 * réellement une requête, celle-ci ne s'exécute jamais (cf. incident du
 * 2026-08-17 documenté dans la classe).
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
        $class = new ReflectionClass($instance);
        $relations = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! RelationMethodGuard::isInvocable($method, $class)) {
                continue;
            }

            $relation = RelationMethodGuard::invoke($method, $instance);

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
        // `MorphTo` étend `BelongsTo` (matcherait donc à tort l'entrée
        // BelongsTo ci-dessous par héritage) mais n'est pas un type supporté
        // par EX-307 : appelée sur une instance neuve, sa cible se résout à
        // l'instance elle-même plutôt qu'au modèle réellement visé (le
        // modèle cible dépend de la valeur de la colonne `*_type`, inconnue
        // hors contexte d'un item précis) — une relation auto-référencée
        // absurde plutôt qu'une vraie relation vers un autre modèle.
        if (! $relation instanceof Relation || $relation instanceof MorphTo) {
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
