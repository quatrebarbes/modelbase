<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ModelTraitInspector;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
}
