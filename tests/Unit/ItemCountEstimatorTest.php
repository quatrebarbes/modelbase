<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ItemCountEstimator;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les requêtes propres à mysql (information_schema)/pgsql (pg_class)/sqlsrv
 * (sys.partitions) ne sont pas exerçables ici, faute de driver PDO
 * correspondant dans l'environnement de développement (limite déjà
 * documentée en Phase 1/4a) — vérifiées manuellement contre l'environnement
 * docker-compose réel (mysql/pgsql) une fois la Phase 17 développée.
 */
class ItemCountEstimatorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    public function test_it_returns_null_for_a_driver_without_row_count_statistics(): void
    {
        Schema::connection('primary')->create('widgets', function (Blueprint $table) {
            $table->id();
        });
        DB::connection('primary')->table('widgets')->insert(['id' => 1]);

        $estimate = (new ItemCountEstimator)->estimate(DB::connection('primary'), 'widgets');

        $this->assertNull($estimate);
    }
}
