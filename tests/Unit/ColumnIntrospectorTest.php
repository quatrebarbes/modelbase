<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ColumnIntrospector;
use Quatrebarbes\Modelbase\Support\ColumnType;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ColumnIntrospectorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
            // Sans ce flag, une colonne JSON sqlite est physiquement stockée
            // en 'text' (cf. SQLiteGrammar::typeJson) et indiscernable d'une
            // colonne string à l'introspection — propre à sqlite, mysql/pgsql
            // exposent nativement un type 'json'.
            'use_native_json' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('primary')->create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::connection('primary')->create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories');
            $table->string('name');
            $table->decimal('price');
            $table->boolean('active');
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
        });
    }

    /**
     * @return array<string, array{name: string, type: string, is_foreign_key: bool, foreign_key: array{table: string, column: string}|null}>
     */
    private function describeProducts(): array
    {
        return collect((new ColumnIntrospector)->forTable('primary', 'products'))->keyBy('name')->all();
    }

    public function test_it_maps_scalar_column_types(): void
    {
        $this->skipUnlessSqliteSupportsNativeJson();

        $columns = $this->describeProducts();

        $this->assertSame(ColumnType::STRING->value, $columns['name']['type']);
        $this->assertSame(ColumnType::NUMBER->value, $columns['price']['type']);
        $this->assertSame(ColumnType::BOOLEAN->value, $columns['active']['type']);
        $this->assertSame(ColumnType::JSON->value, $columns['metadata']['type']);
        $this->assertSame(ColumnType::DATE->value, $columns['published_at']['type']);
    }

    public function test_it_detects_a_foreign_key_column_and_overrides_its_type(): void
    {
        $columns = $this->describeProducts();

        $this->assertTrue($columns['category_id']['is_foreign_key']);
        $this->assertSame(ColumnType::FOREIGN_KEY->value, $columns['category_id']['type']);
        $this->assertSame(
            ['table' => 'categories', 'column' => 'id'],
            $columns['category_id']['foreign_key']
        );
    }

    public function test_a_non_foreign_key_column_carries_no_foreign_key_metadata(): void
    {
        $columns = $this->describeProducts();

        $this->assertFalse($columns['name']['is_foreign_key']);
        $this->assertNull($columns['name']['foreign_key']);
    }
}
