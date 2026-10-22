<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\ModelbaseServiceProvider;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Support\ServiceProvider;

class ModelbaseServiceProviderTest extends TestCase
{
    private function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param  array<string, string>  $paths
     * @return array<string, string>
     */
    private function realpaths(array $paths): array
    {
        return collect($paths)->mapWithKeys(fn ($to, $from) => [realpath($from) => $to])->all();
    }

    /**
     * register() fusionne les valeurs par défaut de config/modelbase.php
     * dans la config de l'app hôte, sans que celle-ci n'ait besoin de les
     * redéclarer.
     */
    public function test_it_merges_the_packages_default_config(): void
    {
        $this->assertSame('modelbase', config('modelbase.route_prefix'));
        $this->assertNull(config('modelbase.guard'));
        $this->assertSame([], config('modelbase.excluded_connections'));
        $this->assertSame(3, config('modelbase.connection_timeout'));
    }

    /**
     * Le fichier de config est publiable sous son propre tag, à
     * l'emplacement conventionnel config/modelbase.php de l'app hôte.
     */
    public function test_it_registers_the_config_file_for_publishing(): void
    {
        $paths = ServiceProvider::pathsToPublish(ModelbaseServiceProvider::class, 'modelbase-config');

        $this->assertSame([
            $this->packageRoot().'/config/modelbase.php' => config_path('modelbase.php'),
        ], $this->realpaths($paths));
    }

    /**
     * Phase 35 : seul asset du front nécessitant encore un build JS
     * (Cytoscape.js, diagramme de relations) est publié sous
     * public/{route_prefix}/diagram-assets, pas public/vendor/... — même
     * principe que le raccordement à `app.baseURL` d'un front SPA classique
     * (cf. ModelbaseServiceProvider), pour rester cohérent avec le préfixe
     * sous lequel le front lui-même (routes/web.php) est servi.
     */
    public function test_it_registers_the_diagram_assets_for_publishing_under_the_configured_route_prefix(): void
    {
        $paths = ServiceProvider::pathsToPublish(ModelbaseServiceProvider::class, 'modelbase-diagram-assets');

        $this->assertSame([
            $this->packageRoot().'/resources/dist/modelbase-diagram' => public_path('modelbase/diagram-assets'),
        ], $this->realpaths($paths));
    }

    /**
     * Une app hôte configurant un préfixe de route différent voit ses assets
     * publiés sous ce même préfixe, pas sous 'modelbase' en dur.
     */
    public function test_the_diagram_assets_publish_destination_follows_a_custom_route_prefix(): void
    {
        config(['modelbase.route_prefix' => 'custom-prefix']);

        (new ModelbaseServiceProvider(app()))->boot();

        $paths = ServiceProvider::pathsToPublish(ModelbaseServiceProvider::class, 'modelbase-diagram-assets');

        $this->assertSame([
            $this->packageRoot().'/resources/dist/modelbase-diagram' => public_path('custom-prefix/diagram-assets'),
        ], $this->realpaths($paths));
    }
}
