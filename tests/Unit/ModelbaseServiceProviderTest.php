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
     * EX-120 : le front n'expose aucun asset à publier — seul le fichier
     * de config reste publiable.
     */
    public function test_no_front_asset_is_registered_for_publishing(): void
    {
        $paths = ServiceProvider::pathsToPublish(ModelbaseServiceProvider::class);

        $this->assertSame([
            $this->packageRoot().'/config/modelbase.php' => config_path('modelbase.php'),
        ], $this->realpaths($paths));
    }

    /**
     * EX-120 : le script du diagramme est inlinable tel quel — un script
     * classique sans `import`, donc sans étape de build.
     */
    public function test_the_diagram_script_is_a_plain_script_shipped_with_the_package(): void
    {
        $path = ModelbaseServiceProvider::diagramScriptPath();

        $this->assertFileExists($path);
        $this->assertDoesNotMatchRegularExpression('/^\s*import\s/m', file_get_contents($path));
    }

    /**
     * EX-121 : Cytoscape.js est chargé depuis les trois CDN publics, dans
     * l'ordre de repli, chacun à la même version figée et sous contrôle
     * d'intégrité SRI.
     */
    public function test_the_diagram_script_loads_cytoscape_from_public_cdns_in_fallback_order(): void
    {
        $script = file_get_contents(ModelbaseServiceProvider::diagramScriptPath());

        preg_match_all("#'(https://[^']+/cytoscape[^']*\.js)'#", $script, $matches);

        $this->assertCount(3, $matches[1]);
        $this->assertStringStartsWith('https://cdnjs.cloudflare.com/', $matches[1][0]);
        $this->assertStringStartsWith('https://cdn.jsdelivr.net/', $matches[1][1]);
        $this->assertStringStartsWith('https://unpkg.com/', $matches[1][2]);

        preg_match_all('/\d+\.\d+\.\d+/', implode(' ', $matches[1]), $versions);
        $this->assertCount(1, array_unique($versions[0]), 'Les trois CDN doivent servir la même version.');

        $this->assertMatchesRegularExpression("/integrity = CYTOSCAPE_INTEGRITY/", $script);
        $this->assertMatchesRegularExpression("/CYTOSCAPE_INTEGRITY = 'sha384-[A-Za-z0-9+\/=]+'/", $script);
    }
}
