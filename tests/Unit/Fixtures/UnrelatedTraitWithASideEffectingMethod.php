<?php

namespace Quatrebarbes\Modelbase\Tests\Unit\Fixtures;

/**
 * Trait volontairement sans rapport avec Eloquent/SoftDeletes/Searchable —
 * matérialise « un futur trait qu'aucune denylist énumérée à la main
 * n'aurait anticipé » pour RelationMethodGuardTest. Déclarée dans son propre
 * fichier (distinct de la classe hôte qui l'utilise) : c'est justement cette
 * différence de fichier que l'allowlist par origine de RelationMethodGuard
 * exploite pour l'exclure.
 */
trait UnrelatedTraitWithASideEffectingMethod
{
    public function someSideEffectingMethod(): bool
    {
        return true;
    }
}
