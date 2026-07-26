<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ModelTraitInspector;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class ModelTraitInspectorTest extends TestCase
{
    public function test_it_detects_a_trait_used_by_the_model(): void
    {
        $model = new class extends Model
        {
            use SoftDeletes;
        };

        $this->assertTrue(ModelTraitInspector::uses($model, SoftDeletes::class));
    }

    public function test_it_returns_false_when_the_model_does_not_use_the_trait(): void
    {
        $model = new class extends Model {};

        $this->assertFalse(ModelTraitInspector::uses($model, SoftDeletes::class));
    }

    /**
     * EX-444 (Phase 13) : même mécanisme générique que SoftDeletes ci-dessus,
     * vérifié explicitement contre le trait Scout Searchable — celui-ci
     * n'est pas composé d'autres traits en interne (seul un `use` d'import
     * d'espace de noms y référence SoftDeletes, sans le composer), donc sans
     * risque de faux positif via class_uses_recursive().
     */
    public function test_it_detects_the_scout_searchable_trait(): void
    {
        $model = new class extends Model
        {
            use Searchable;
        };

        $this->assertTrue(ModelTraitInspector::uses($model, Searchable::class));
    }

    public function test_it_returns_false_for_searchable_when_the_model_does_not_use_it(): void
    {
        $model = new class extends Model {};

        $this->assertFalse(ModelTraitInspector::uses($model, Searchable::class));
    }
}
