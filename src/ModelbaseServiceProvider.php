<?php

namespace Quatrebarbes\Modelbase;

use Illuminate\Support\ServiceProvider;

class ModelbaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/modelbase.php', 'modelbase');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/modelbase.php' => config_path('modelbase.php'),
            ], 'modelbase-config');
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }
}
