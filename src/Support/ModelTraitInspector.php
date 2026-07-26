<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Détection générique d'un trait Eloquent utilisé par un modèle hôte, via
 * class_uses_recursive() (couvre un trait hérité d'une classe parente,
 * contrairement à class_uses() natif qui ne regarde que la classe exacte) —
 * réutilisée pour SoftDeletes (EX-437, Phase 12) et, à terme, Searchable
 * (EX-444, Phase 13) plutôt que de dupliquer une détection ad hoc par trait.
 */
final class ModelTraitInspector
{
    /**
     * @param  class-string  $trait
     */
    public static function uses(Model $instance, string $trait): bool
    {
        return in_array($trait, class_uses_recursive($instance), true);
    }
}
