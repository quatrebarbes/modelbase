<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionMethod;

/**
 * Méthodes publiques sans paramètre requis à ne jamais invoquer à l'aveugle
 * lors d'une introspection par réflexion du modèle hôte — utilisé à la fois
 * par ColumnIntrospector::relationForeignKeys() (EX-423) et RelationIntrospector
 * (EX-307/EX-425), pour ne jamais faire diverger ce garde-fou de sécurité
 * entre les deux mécanismes de réflexion. Couvre celles de `Model` lui-même
 * (save/delete/push/touch/refresh/fresh/replicate/..., capturées d'un bloc
 * via réflexion plutôt qu'énumérées à la main) plus quelques méthodes
 * fournies par des traits courants mais absentes de `Model`, aux effets de
 * bord réels si invoquées (SoftDeletes). Limite documentée : un trait tiers
 * ajoutant sa propre méthode publique sans paramètre et à effet de bord (hors
 * SoftDeletes) ne serait pas couvert par cette liste.
 */
final class RelationMethodDenylist
{
    /**
     * @return array<int, string>
     */
    public static function get(): array
    {
        static $denylist = null;

        if ($denylist === null) {
            $denylist = array_map(
                fn (ReflectionMethod $method) => $method->getName(),
                (new ReflectionClass(Model::class))->getMethods(ReflectionMethod::IS_PUBLIC)
            );

            $denylist = array_merge($denylist, ['restore', 'forceDelete', 'trashed', 'isForceDeleting']);
        }

        return $denylist;
    }
}
