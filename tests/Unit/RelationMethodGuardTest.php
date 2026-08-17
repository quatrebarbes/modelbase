<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\RelationMethodGuard;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Quatrebarbes\Modelbase\Tests\Unit\Fixtures\UnrelatedTraitWithASideEffectingMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ReflectionClass;

/**
 * Suite dédiée à RelationMethodGuard, extraite de RelationIntrospectorTest/
 * ColumnIntrospectorTest (qui restent focalisées sur EX-307/EX-423) pour
 * couvrir directement la propriété de sécurité elle-même : l'allowlist par
 * origine doit exclure *toute* méthode qui ne vient pas du fichier du modèle
 * hôte, y compris une méthode qu'aucune liste énumérée à la main n'aurait
 * anticipée (cf. incident du 2026-08-03, docs/roadmap.md Phase 12) — c'est
 * précisément ce que l'ancienne denylist ne pouvait pas garantir.
 */
class RelationMethodGuardTest extends TestCase
{
    public function test_it_allows_a_method_declared_by_the_host_model_itself(): void
    {
        $instance = new class extends Model
        {
            public function category(): BelongsTo
            {
                return $this->belongsTo(self::class);
            }
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('category');

        $this->assertTrue(RelationMethodGuard::isInvocable($method, $class));
    }

    public function test_it_rejects_a_method_inherited_from_the_base_model_class(): void
    {
        $instance = new class extends Model {};

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('delete');

        $this->assertFalse(RelationMethodGuard::isInvocable($method, $class));
    }

    /**
     * Cas déjà couvert par RelationIntrospectorTest/ColumnIntrospectorTest,
     * revérifié ici au niveau du garde-fou lui-même : `forceDeleteQuietly`
     * (SoftDeletes) n'est ni déclarée par `Model` ni par le modèle hôte —
     * seule l'allowlist par origine (fichier différent de celui du modèle
     * hôte) l'exclut, la denylist par nom ne la couvrait pas avant correction.
     */
    public function test_it_rejects_a_soft_deletes_trait_method(): void
    {
        $instance = new class extends Model
        {
            use SoftDeletes;
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('forceDeleteQuietly');

        $this->assertFalse(RelationMethodGuard::isInvocable($method, $class));
    }

    /**
     * La propriété de sécurité recherchée : *n'importe quel* trait ajoutant
     * une méthode publique sans paramètre requis doit être exclu, pas
     * seulement ceux déjà connus (SoftDeletes/Searchable) — cf. limite de
     * l'ancienne denylist (avant ce garde-fou en deux niveaux), qui exigeait
     * d'anticiper chaque méthode dangereuse une par une. Ce
     * trait fictif, sans rapport avec Eloquent, matérialise « un futur trait
     * qu'aucune liste énumérée à la main n'aurait anticipé ».
     */
    public function test_it_rejects_a_method_from_an_arbitrary_unrelated_trait(): void
    {
        $instance = new class extends Model
        {
            use UnrelatedTraitWithASideEffectingMethod;
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('someSideEffectingMethod');

        $this->assertFalse(RelationMethodGuard::isInvocable($method, $class));
    }

    /**
     * Défense en profondeur de la denylist explicite, au-delà de l'allowlist
     * par origine : un modèle hôte redéclarant directement (dans son propre
     * fichier) une méthode `Model` sensible reste exclu, même si l'allowlist
     * par origine seule l'aurait laissé passer (même fichier que la classe).
     */
    public function test_it_rejects_a_model_builtin_method_overridden_in_the_host_models_own_file(): void
    {
        $instance = new class extends Model
        {
            public function delete()
            {
                return parent::delete();
            }
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('delete');

        $this->assertFalse(RelationMethodGuard::isInvocable($method, $class));
    }

    public function test_it_rejects_a_method_requiring_a_parameter(): void
    {
        $instance = new class extends Model
        {
            public function withRequiredParam(string $x): BelongsTo
            {
                return $this->belongsTo(self::class);
            }
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('withRequiredParam');

        $this->assertFalse(RelationMethodGuard::isInvocable($method, $class));
    }

    public function test_it_rejects_a_static_method(): void
    {
        $instance = new class extends Model
        {
            public static function someStaticMethod(): void
            {
            }
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('someStaticMethod');

        $this->assertFalse(RelationMethodGuard::isInvocable($method, $class));
    }

    /**
     * Incident du 2026-08-17 (listing des modèles sur pgsql, OOM dans
     * `Connection::select()`) : une méthode publique sans paramètre requis,
     * déclarée dans le modèle hôte, qui exécute elle-même une lecture non
     * bornée plutôt que de construire une relation, chargeait tout son
     * résultat en mémoire avant d'être écartée au constat que ce n'est pas
     * une `BelongsTo`. Dès que le type de retour déclaré exclut une
     * `Relation` (ici `Collection`), la méthode ne doit plus être invoquée du
     * tout.
     */
    public function test_it_rejects_a_method_whose_declared_return_type_is_not_a_relation(): void
    {
        $instance = new class extends Model
        {
            public function history(): \Illuminate\Support\Collection
            {
                return $this->hasMany(self::class)->get();
            }
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('history');

        $this->assertFalse(RelationMethodGuard::isInvocable($method, $class));
    }

    /**
     * Un type de retour natif plus précis (`void`, `bool`, `array`...) exclut
     * tout autant une `Relation` qu'une classe concrète non `Relation`.
     */
    public function test_it_rejects_a_method_with_a_scalar_return_type(): void
    {
        $instance = new class extends Model
        {
            public function isSomething(): bool
            {
                return true;
            }
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('isSomething');

        $this->assertFalse(RelationMethodGuard::isInvocable($method, $class));
    }

    /**
     * `self`/`static` désignent le modèle hôte lui-même, jamais une Relation
     * — exclu sans invocation même si aucun autre garde-fou ne le couvrirait.
     */
    public function test_it_rejects_a_method_returning_static(): void
    {
        $instance = new class extends Model
        {
            public function withStaticReturnType(): static
            {
                return $this;
            }
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('withStaticReturnType');

        $this->assertFalse(RelationMethodGuard::isInvocable($method, $class));
    }

    /**
     * Sans type de retour déclaré, impossible de trancher sans invoquer —
     * comportement historique conservé (risque résiduel documenté).
     */
    public function test_it_allows_a_method_without_a_declared_return_type(): void
    {
        $instance = new class extends Model
        {
            public function category()
            {
                return $this->belongsTo(self::class);
            }
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('category');

        $this->assertTrue(RelationMethodGuard::isInvocable($method, $class));
    }

    /**
     * `mixed` est inconclusif (une Relation est un objet, qui peut être
     * déclaré sous un type large) : ne doit pas exclure la méthode.
     */
    public function test_it_allows_a_method_with_a_mixed_return_type(): void
    {
        $instance = new class extends Model
        {
            public function category(): mixed
            {
                return $this->belongsTo(self::class);
            }
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('category');

        $this->assertTrue(RelationMethodGuard::isInvocable($method, $class));
    }

    /**
     * Niveau 4 (invoke(), incident du 2026-08-17) : la construction d'une
     * relation Eloquent réelle continue de fonctionner normalement sous
     * `Connection::pretend()` — `addConstraints()` ne fait qu'ajouter une
     * clause `where`, jamais exécutée à la construction.
     */
    public function test_invoke_returns_the_actual_relation_for_a_genuine_relation_method(): void
    {
        $instance = new class extends Model
        {
            public function category()
            {
                return $this->belongsTo(self::class);
            }
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('category');

        $this->assertInstanceOf(BelongsTo::class, RelationMethodGuard::invoke($method, $instance));
    }

    /**
     * Niveau 4 (invoke(), incident du 2026-08-17) : preuve que la requête
     * n'est jamais réellement exécutée, pas seulement qu'une éventuelle
     * exception est avalée silencieusement — sous exécution réelle,
     * interroger une table inexistante lèverait une `QueryException` ;
     * `pretend()` court-circuite `Connection::select()` avant même d'ouvrir
     * la table, renvoyant un résultat vide sans jamais toucher le PDO
     * sous-jacent. Ce test échouerait (exception non attrapée) sans le
     * niveau 4 — une méthode sans type de retour déclaré comme celle-ci
     * échappe au niveau 3 (filtre par type).
     */
    public function test_invoke_neutralizes_a_query_the_method_would_actually_execute(): void
    {
        $instance = new class extends Model
        {
            protected $table = 'this_table_does_not_exist_anywhere';

            public function history()
            {
                return $this->newQuery()->get();
            }
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('history');

        $result = RelationMethodGuard::invoke($method, $instance);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
        $this->assertCount(0, $result);
    }

    /**
     * Comportement conservé de l'ancien `catch (Throwable) { continue; }`
     * dupliqué jusqu'ici dans les deux call sites, désormais centralisé ici.
     */
    public function test_invoke_returns_null_when_the_method_throws(): void
    {
        $instance = new class extends Model
        {
            public function broken()
            {
                throw new \RuntimeException('boom');
            }
        };

        $class = new ReflectionClass($instance);
        $method = $class->getMethod('broken');

        $this->assertNull(RelationMethodGuard::invoke($method, $instance));
    }
}
