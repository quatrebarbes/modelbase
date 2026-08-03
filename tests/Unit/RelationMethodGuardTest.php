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
}
