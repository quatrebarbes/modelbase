<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionMethod;

/**
 * Détermine si une méthode publique sans paramètre requis du modèle hôte peut
 * être invoquée sans risque lors d'une introspection par réflexion —
 * consommé à la fois par ColumnIntrospector::relationForeignKeys() (EX-423)
 * et RelationIntrospector (EX-307/EX-425), pour ne jamais faire diverger ce
 * garde-fou de sécurité entre les deux mécanismes de réflexion.
 *
 * Deux niveaux de protection, du plus fort au plus faible :
 *
 * 1. Allowlist par origine (isInvocable) : une méthode n'est candidate que si
 *    elle est physiquement déclarée dans le fichier du modèle hôte lui-même
 *    (`ReflectionMethod::getFileName() === ReflectionClass::getFileName()`),
 *    jamais héritée d'une classe parente ou d'un trait (`Model`, `SoftDeletes`,
 *    `Searchable`, `HasFactory`, un futur trait...). Les relations Eloquent
 *    (`category()`, `tags()`...) sont par construction déclarées directement
 *    dans le modèle hôte : cette allowlist les couvre sans jamais avoir à
 *    connaître leur nom à l'avance, tout en excluant d'un bloc n'importe
 *    quelle méthode à effet de bord fournie par le framework ou un package
 *    tiers — y compris une future méthode qu'aucune liste énumérée à la main
 *    n'aurait anticipée (cf. incident Phase 12 ci-dessous).
 * 2. Denylist explicite (defense en profondeur) : ne couvre plus que le cas,
 *    plus rare, où le modèle hôte redéclare directement une méthode Eloquent
 *    sensible dans son propre fichier (ex. override de `delete()`) — un cas
 *    que l'allowlist par origine, seule, ne suffirait pas à exclure.
 *
 * Limite documentée : une relation déclarée non pas directement dans le
 * modèle hôte mais via un trait *propre à l'application hôte* (ex. un
 * `trait HasComments` partagé entre plusieurs modèles) ne serait pas détectée
 * par l'allowlist ci-dessus (absente du diagramme/des tableaux d'objets liés)
 * — compromis assumé : un faux négatif ici n'a qu'un effet cosmétique,
 * contrairement à un faux positif (cf. incident Phase 12).
 *
 * Historique : jusqu'à ce garde-fou en deux niveaux, seule la denylist
 * existait — elle couvrait les méthodes publiques de `Model` lui-même plus
 * `restore`/`forceDelete`/`trashed`/`isForceDeleting` (SoftDeletes), mais pas
 * `forceDeleteQuietly()`/`restoreQuietly()` (également fournies par
 * SoftDeletes, absentes de `Model`) : invoquées à l'aveugle sur un item
 * réellement récupéré (`find()`), `forceDeleteQuietly()` le supprimait
 * physiquement — simplement consulter le tableau d'objets liés ou modifier un
 * item suffisait à le détruire (cf. docs/roadmap.md, Phase 12, incident du
 * 2026-08-03). Une denylist exige d'anticiper chaque méthode dangereuse une
 * par une ; l'allowlist par origine ci-dessus ferme toute la classe de bug
 * (n'importe quel futur trait ajoutant une méthode publique à effet de bord)
 * sans avoir à l'énumérer.
 */
final class RelationMethodGuard
{
    /**
     * @param  ReflectionClass<Model>  $class
     */
    public static function isInvocable(ReflectionMethod $method, ReflectionClass $class): bool
    {
        if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        if ($method->getFileName() !== $class->getFileName()) {
            return false;
        }

        return ! in_array($method->getName(), self::denylist(), true);
    }

    /**
     * @return array<int, string>
     */
    private static function denylist(): array
    {
        static $denylist = null;

        if ($denylist === null) {
            $denylist = array_map(
                fn (ReflectionMethod $method) => $method->getName(),
                (new ReflectionClass(Model::class))->getMethods(ReflectionMethod::IS_PUBLIC)
            );
        }

        return $denylist;
    }
}
