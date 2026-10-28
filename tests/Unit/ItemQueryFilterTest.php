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
            $table->decimal('price', 8, 2);
            $table->date('released_on');
            $table->dateTime('published_at');
            $table->time('opens_at');
        });

        DB::connection('primary')->table('products')->insert([
            ['category_id' => 1, 'name' => 'Hammer', 'active' => true, 'price' => 12, 'released_on' => '2024-03-15', 'published_at' => '2024-03-15 14:30:15', 'opens_at' => '14:30:15'],
            ['category_id' => 99, 'name' => 'Orphan', 'active' => false, 'price' => 3.5, 'released_on' => '2024-04-01', 'published_at' => '2024-03-15 15:00:00', 'opens_at' => '23:45:00'],
            ['category_id' => 1, 'name' => 'Anvil', 'active' => true, 'price' => -7.25, 'released_on' => '2023-12-31', 'published_at' => '2023-12-31 23:59:59', 'opens_at' => '08:00:00'],
        ]);
    }

    /**
     * @return array<string, array{type: string, temporal?: ?string, key_format?: ?string}>
     */
    private function columns(): array
    {
        return [
            'category_id' => ['type' => ColumnType::FOREIGN_KEY->value, 'key_format' => 'integer'],
            'name' => ['type' => ColumnType::STRING->value],
            'active' => ['type' => ColumnType::BOOLEAN->value],
            'price' => ['type' => ColumnType::NUMBER->value],
            'released_on' => ['type' => ColumnType::DATE->value, 'temporal' => 'date'],
            'published_at' => ['type' => ColumnType::DATE->value, 'temporal' => 'datetime'],
            'opens_at' => ['type' => ColumnType::DATE->value, 'temporal' => 'time'],
            'payload' => ['type' => ColumnType::JSON->value],
        ];
    }

    private function names(\Illuminate\Database\Query\Builder $query): array
    {
        return $query->orderBy('id')->pluck('name')->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    private function filtered(array $filters): array
    {
        $query = DB::connection('primary')->table('products');

        (new ItemQueryFilter)->applyFilters($query, $filters, $this->columns());

        return $this->names($query);
    }

    public function test_apply_filters_matches_a_text_column_case_insensitively_as_a_contains(): void
    {
        $this->assertSame(['Hammer'], $this->filtered(['name' => 'HAM']));
    }

    public function test_apply_filters_combines_multiple_filters_with_and(): void
    {
        $this->assertSame(['Anvil'], $this->filtered(['category_id' => '1', 'name' => 'Anv']));
    }

    /**
     * EX-485 : égalité numérique, quel que soit le séparateur décimal ou la
     * présence de zéros décimaux.
     */
    public function test_apply_filters_matches_a_number_column_by_numeric_equality(): void
    {
        foreach (['12', '12.0', '12,00', '012'] as $value) {
            $this->assertSame(['Hammer'], $this->filtered(['price' => $value]), $value);
        }

        $this->assertSame(['Orphan'], $this->filtered(['price' => '3,5']));
        $this->assertSame(['Anvil'], $this->filtered(['price' => '-7.25']));
        $this->assertSame([], $this->filtered(['price' => '12.5']));
    }

    public function test_a_malformed_number_is_invalid(): void
    {
        $invalid = (new ItemQueryFilter)->invalidFilters(
            ['price' => '1 000', 'name' => '1 000'],
            $this->columns(),
        );

        $this->assertSame(['price' => 'number'], $invalid);

        foreach (['1e3', '1.2.3', '1,2.3', '-', '+12', 'abc', '.'] as $value) {
            $this->assertArrayHasKey('price', (new ItemQueryFilter)->invalidFilters(['price' => $value], $this->columns()), $value);
        }
    }

    /**
     * EX-486
     */
    public function test_apply_filters_matches_a_boolean_column(): void
    {
        $this->assertSame(['Hammer', 'Anvil'], $this->filtered(['active' => '1']));
        $this->assertSame(['Orphan'], $this->filtered(['active' => '0']));
        $this->assertSame(['active' => 'boolean'], (new ItemQueryFilter)->invalidFilters(['active' => 'oui'], $this->columns()));
    }

    /**
     * EX-487 : égalité stricte, sans contrôle de format — une saisie
     * incompatible avec une clé entière ne retient aucun item, sans erreur.
     */
    public function test_apply_filters_matches_a_foreign_key_by_strict_equality_without_format_control(): void
    {
        $this->assertSame(['Hammer', 'Anvil'], $this->filtered(['category_id' => '1']));
        $this->assertSame([], (new ItemQueryFilter)->invalidFilters(['category_id' => 'abc'], $this->columns()));
        $this->assertSame([], $this->filtered(['category_id' => 'abc']));
        $this->assertSame([], $this->filtered(['category_id' => '1.0']));
    }

    /**
     * EX-488/EX-491 : la saisie désigne une période, de son début inclus à
     * son début suivant exclu.
     */
    public function test_apply_filters_matches_a_datetime_column_by_period(): void
    {
        $this->assertSame(['Hammer', 'Orphan'], $this->filtered(['published_at' => '2024']));
        $this->assertSame(['Hammer', 'Orphan'], $this->filtered(['published_at' => '2024-03']));
        $this->assertSame(['Hammer', 'Orphan'], $this->filtered(['published_at' => '2024-03-15']));
        $this->assertSame(['Hammer'], $this->filtered(['published_at' => '2024-03-15 14']));
        $this->assertSame(['Hammer'], $this->filtered(['published_at' => '2024-03-15T14:30']));
        $this->assertSame(['Hammer'], $this->filtered(['published_at' => '2024-03-15 14:30:15']));
        $this->assertSame([], $this->filtered(['published_at' => '2024-03-15 14:30:16']));
        $this->assertSame(['Anvil'], $this->filtered(['published_at' => '2023-12-31 23']));
    }

    /**
     * EX-489
     */
    public function test_apply_filters_matches_a_date_column_by_period(): void
    {
        $this->assertSame(['Hammer', 'Orphan'], $this->filtered(['released_on' => '2024']));
        $this->assertSame(['Hammer'], $this->filtered(['released_on' => '2024-03']));
        $this->assertSame(['Anvil'], $this->filtered(['released_on' => '2023-12-31']));
        $this->assertSame(['released_on' => 'date'], (new ItemQueryFilter)->invalidFilters(['released_on' => '2024-03-15 14'], $this->columns()));
    }

    /**
     * EX-490/EX-491 : `23` retient la dernière heure de la journée, sans
     * borne supérieure hors du domaine d'une heure.
     */
    public function test_apply_filters_matches_a_time_column_by_period(): void
    {
        $this->assertSame(['Hammer'], $this->filtered(['opens_at' => '14']));
        $this->assertSame(['Hammer'], $this->filtered(['opens_at' => '14:30']));
        $this->assertSame(['Hammer'], $this->filtered(['opens_at' => '14:30:15']));
        $this->assertSame(['Orphan'], $this->filtered(['opens_at' => '23']));
        $this->assertSame(['opens_at' => 'time'], (new ItemQueryFilter)->invalidFilters(['opens_at' => '2024-03-15'], $this->columns()));
    }

    /**
     * EX-488 à EX-490 et avertissements associés : saisie mal formée, en
     * cours de frappe ou désignant une date/heure inexistante.
     */
    public function test_a_malformed_or_nonexistent_date_is_invalid(): void
    {
        $filter = new ItemQueryFilter;

        foreach (['202', '2024-', '2024-3', '2024-02-30', '2024-13', '0000', '2024-03-15 25', '2024-03-15 14:60', '2024-03-15t14', '2024/03/15', '15/03/2024'] as $value) {
            $this->assertSame(['published_at' => 'datetime'], $filter->invalidFilters(['published_at' => $value], $this->columns()), $value);
        }

        foreach (['25', '25:00', '14:', '1', '14:30:60'] as $value) {
            $this->assertSame(['opens_at' => 'time'], $filter->invalidFilters(['opens_at' => $value], $this->columns()), $value);
        }

        $this->assertSame([], $filter->invalidFilters(['published_at' => '2024-02-29', 'name' => '202'], $this->columns()));
    }

    /**
     * EX-495 : une valeur invalide rejette la requête, en nommant la colonne
     * et le format attendu.
     */
    public function test_apply_filters_throws_for_an_invalid_value_naming_the_column_and_format(): void
    {
        $query = DB::connection('primary')->table('products');

        try {
            (new ItemQueryFilter)->applyFilters($query, ['published_at' => '2024-', 'name' => 'Ham'], $this->columns());
            $this->fail('ItemFilterException attendue.');
        } catch (ItemFilterException $exception) {
            $this->assertSame(['published_at'], array_keys($exception->errors()));
            $this->assertStringContainsString('published_at', $exception->errors()['published_at'][0]);
            $this->assertStringContainsString('AAAA-MM-JJ HH:MM:SS', $exception->errors()['published_at'][0]);
        }
    }

    public function test_a_non_scalar_value_is_invalid_whatever_the_column_type(): void
    {
        $this->assertSame(
            ['name' => 'text', 'price' => 'number'],
            (new ItemQueryFilter)->invalidFilters(['name' => ['x'], 'price' => ['1']], $this->columns()),
        );
    }

    public function test_apply_filters_throws_for_an_unknown_column(): void
    {
        $query = DB::connection('primary')->table('products');

        $this->expectException(ItemFilterException::class);

        try {
            (new ItemQueryFilter)->applyFilters($query, ['does_not_exist' => 'x'], $this->columns());
        } catch (ItemFilterException $exception) {
            $this->assertArrayHasKey('does_not_exist', $exception->errors());

            throw $exception;
        }
    }

    public function test_apply_sort_orders_by_a_single_column_descending(): void
    {
        $query = DB::connection('primary')->table('products');

        (new ItemQueryFilter)->applySort($query, '-name', $this->columns());

        $this->assertSame(['Orphan', 'Hammer', 'Anvil'], $query->pluck('name')->all());
    }

    /**
     * L'ordre d'appel de orderBy() (donc l'ordre du paramètre `sort`) est
     * l'ordre de priorité : category_id d'abord (groupe les deux category_id
     * = 1 ensemble), puis name en second critère au sein de chaque groupe.
     */
    public function test_apply_sort_orders_by_multiple_columns_in_priority_order(): void
    {
        $query = DB::connection('primary')->table('products');

        (new ItemQueryFilter)->applySort($query, 'category_id,-name', $this->columns());

        $this->assertSame(['Hammer', 'Anvil', 'Orphan'], $query->pluck('name')->all());
    }

    public function test_apply_sort_throws_for_an_unknown_column(): void
    {
        $query = DB::connection('primary')->table('products');

        $this->expectException(ItemFilterException::class);

        try {
            (new ItemQueryFilter)->applySort($query, 'does_not_exist', $this->columns());
        } catch (ItemFilterException $exception) {
            $this->assertArrayHasKey('does_not_exist', $exception->errors());

            throw $exception;
        }
    }
}
