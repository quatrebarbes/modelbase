<?php

namespace Quatrebarbes\Modelbase\Tests;

use Quatrebarbes\Modelbase\ModelbaseServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ModelbaseServiceProvider::class,
        ];
    }
}
