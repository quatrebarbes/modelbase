<?php

namespace Quatrebarbes\Modelbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Détermine si une méthode publique sans paramètre requis du modèle hôte peut
 * être invoquée sans risque lors d'une introspection par réflexion —
 * consommé à la fois par ColumnIntrospector::relationForeignKeys() (EX-423)
 * et RelationIntrospector (EX-307/EX-425), pour ne jamais faire diverger ce
 * garde-fou de sécurité entre les deux mécanismes de réflexion.
 *
 * Quatre niveaux de protection, du plus fort au plus faible :
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
 * 3. Filtre par type de retour déclaré (defense en profondeur, incident du
 *    2026-08-17 ci-dessous) : une méthode déclarant un type de retour qui ne
 *    peut *pas* être une `Relation` (type natif — `void`/`bool`/`array`/...
 *    hors `mixed`/`object`, inconclusifs — ou classe concrète non `Relation`,
 *    y compris `self`/`static`) est exclue sans être invoquée. Sans type
 *    déclaré, union ou intersection de types : invocation nécessaire, faute
 *    de pouvoir trancher.
 * 4. Invocation sous `Connection::pretend()` (`invoke()` ci-dessous, defense
 *    en profondeur complémentaire au niveau 3, même incident) : quand
 *    l'invocation reste nécessaire (méthode sans type de retour déclaré,
 *    échappant au niveau 3), elle a lieu à l'intérieur d'un `pretend()` sur
 *    la connexion de l'instance — toute requête que la méthode exécuterait
 *    réellement (au lieu de se contenter de construire une relation) est
 *    court-circuitée par Laravel lui-même (`Connection::select()` renvoie `[]`
 *    sans jamais toucher le PDO sous-jacent), donc sans le moindre risque
 *    d'épuisement mémoire, quel que soit le type de retour déclaré. Ne
 *    protège que la connexion de l'instance elle-même (une requête sur une
 *    *autre* connexion en dur échapperait à ce filtre, mais serait alors
 *    typiquement typée et donc déjà exclue par le niveau 3) et pas un effet
 *    de bord purement PHP sans requête SQL (boucle lourde, fichier chargé en
 *    mémoire) — risque résiduel assumé.
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
 *
 * Incident du 2026-08-17 (listing des modèles sur pgsql, `Allowed memory size
 * ... exhausted` dans `Connection::select()`) : construire une relation
 * n'exécute aucune requête, mais rien ne garantissait que la méthode invoquée
 * en soit réellement une — une méthode publique sans paramètre requis
 * déclarée directement dans le modèle hôte (donc couverte par l'allowlist
 * ci-dessus) qui exécute elle-même une lecture non bornée (ex. `public
 * function history(): Collection { return $this->hasMany(...)->get(); }`)
 * était invoquée à l'aveugle, chargeant tout le résultat en mémoire avant
 * d'être écartée au constat que ce n'est pas une `BelongsTo`/`Relation`. Le
 * filtre par type de retour (niveau 3) couvre ce cas dès que la méthode
 * déclare un type de retour explicite (le cas courant pour un accesseur ou
 * une relation Eloquent moderne) ; `invoke()` (niveau 4, ajouté dans la
 * foulée à la demande de l'utilisateur — « est-ce qu'on aurait pu s'en sortir
 * avec un pretend ? ») couvre le risque résiduel laissé ouvert par le niveau
 * 3 seul (méthode sans type de retour déclaré) pour cette même classe de bug,
 * en empêchant la requête elle-même de s'exécuter plutôt qu'en essayant de la
 * prédire depuis un type déclaré.
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

        if (in_array($method->getName(), self::denylist(), true)) {
            return false;
        }

        return self::returnTypeAllowsInvocation($method);
    }

    /**
     * Invoque une méthode déjà validée par `isInvocable()` ci-dessus, sous
     * `Connection::pretend()` (niveau 4 de la doc de classe) — `null` si
     * l'invocation lève une exception, comme le faisaient auparavant les deux
     * call sites (`ColumnIntrospector::relationForeignKeys()`/
     * `RelationIntrospector::relationsOf()`) avant extraction ici. La valeur
     * de retour de la méthode est récupérée via une variable capturée par
     * référence, `pretend()` renvoyant lui-même le journal de requêtes
     * (vide ici, aucune requête n'étant réellement exécutée), pas le résultat
     * du callback.
     */
    public static function invoke(ReflectionMethod $method, Model $instance): mixed
    {
        $result = null;

        try {
            $instance->getConnection()->pretend(function () use ($method, $instance, &$result) {
                $result = $method->invoke($instance);
            });
        } catch (Throwable) {
            return null;
        }

        return $result;
    }

    /**
     * `false` uniquement lorsque le type de retour déclaré exclut à coup sûr
     * une `Relation` — un type composé (union/intersection) ou l'absence de
     * type déclaré ne permet pas de trancher sans invoquer la méthode.
     */
    private static function returnTypeAllowsInvocation(ReflectionMethod $method): bool
    {
        $type = $method->getReturnType();

        if (! $type instanceof ReflectionNamedType) {
            return true;
        }

        if ($type->isBuiltin()) {
            // `mixed`/`object` sont inconclusifs (une Relation est un objet,
            // et peut être renvoyée sous un type large) : seuls les types
            // natifs plus précis (void/bool/int/float/string/array/
            // callable/iterable/never/null/true/false) excluent réellement
            // une Relation.
            return in_array(strtolower($type->getName()), ['mixed', 'object'], true);
        }

        $name = $type->getName();

        // `self`/`static`/`parent` désignent le modèle hôte lui-même (ou un
        // ancêtre), jamais une Relation.
        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            return false;
        }

        return is_a($name, Relation::class, true);
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
