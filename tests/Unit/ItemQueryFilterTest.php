<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\ColumnType;
use Quatrebarbes\Modelbase\Support\ItemFilterException;
use Quatrebarbes\Modelbase\Support\ItemQueryFilter;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ItemQueryFilterTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.primary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('primary')->create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->string('name');
            $table->boolean('active');
        });

        DB::connection('primary')->table('products')->insert([
            ['category_id' => 1, 'name' => 'Hammer', 'active' => true],
            ['category_id' => 99, 'name' => 'Orphan', 'active' => false],
            ['category_id' => 1, 'name' => 'Anvil', 'active' => true],
        ]);
    }

    /**
     * @return array<string, ColumnType>
     */
    private function columnTypes(): array
    {
        return [
            'category_id' => ColumnType::NUMBER,
            'name' => ColumnType::STRING,
            'active' => ColumnType::BOOLEAN,
        ];
    }

    private function names(\Illuminate\Database\Query\Builder $query): array
    {
        return $query->pluck('name')->all();
    }

    public function test_apply_filters_matches_a_text_column_case_insensitively_as_a_contains(): void
    {
        $query = DB::connection('primary')->table('products');

        (new ItemQueryFilter)->applyFilters($query, ['name' => 'HAM'], $this->columnTypes());

        $this->assertSame(['Hammer'], $this->names($query));
    }

    public function test_apply_filters_matches_a_non_text_column_by_strict_equality(): void
    {
        $query = DB::connection('primary')->table('products');

        (new ItemQueryFilter)->applyFilters($query, ['category_id' => 1], $this->columnTypes());

        $this->assertSame(['Hammer', 'Anvil'], $this->names($query));
    }

    public function test_apply_filters_combines_multiple_filters_with_and(): void
    {
        $query = DB::connection('primary')->table('products');

        (new ItemQueryFilter)->applyFilters($query, ['category_id' => 1, 'name' => 'Anv'], $this->columnTypes());

        $this->assertSame(['Anvil'], $this->names($query));
    }

    public function test_apply_filters_throws_for_an_unknown_column(): void
    {
        $query = DB::connection('primary')->table('products');

        $this->expectException(ItemFilterException::class);

        try {
            (new ItemQueryFilter)->applyFilters($query, ['does_not_exist' => 'x'], $this->columnTypes());
        } catch (ItemFilterException $exception) {
            $this->assertArrayHasKey('does_not_exist', $exception->errors());

            throw $exception;
        }
    }

    public function test_apply_sort_orders_by_a_single_column_descending(): void
    {
        $query = DB::connection('primary')->table('products');

        (new ItemQueryFilter)->applySort($query, '-name', $this->columnTypes());

        $this->assertSame(['Orphan', 'Hammer', 'Anvil'], $this->names($query));
    }

    /**
     * L'ordre d'appel de orderBy() (donc l'ordre du paramètre `sort`) est
     * l'ordre de priorité : category_id d'abord (groupe les deux category_id
     * = 1 ensemble), puis name en second critère au sein de chaque groupe.
     */
    public function test_apply_sort_orders_by_multiple_columns_in_priority_order(): void
    {
        $query = DB::connection('primary')->table('products');

        (new ItemQueryFilter)->applySort($query, 'category_id,-name', $this->columnTypes());

        $this->assertSame(['Hammer', 'Anvil', 'Orphan'], $this->names($query));
    }

    public function test_apply_sort_throws_for_an_unknown_column(): void
    {
        $query = DB::connection('primary')->table('products');

        $this->expectException(ItemFilterException::class);

        try {
            (new ItemQueryFilter)->applySort($query, 'does_not_exist', $this->columnTypes());
        } catch (ItemFilterException $exception) {
            $this->assertArrayHasKey('does_not_exist', $exception->errors());

            throw $exception;
        }
    }
}
