<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Résout le nom de modèle d'une route (`{model}`, le `class_basename` utilisé
 * par le listing du module 3) vers la classe Eloquent déclarée pour une
 * connexion donnée — utilisé par EnsureModelIsNavigable (EX-102 : l'accès à
 * ce niveau de navigation ne dépend que de la disponibilité du modèle pour la
 * connexion parente, jamais d'un droit utilisateur) et par ItemRepository.
 */
class ModelResolver
{
    public function __construct(private EloquentModelFinder $models)
    {
    }

    /**
     * @return class-string<Model>|null
     */
    public function resolve(string $connection, string $model): ?string
    {
        return collect($this->models->forConnection($connection))
            ->first(fn (string $class) => class_basename($class) === $model);
    }
}
