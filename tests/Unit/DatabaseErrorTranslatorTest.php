<?php

namespace Quatrebarbes\Modelbase\Tests\Unit;

use Quatrebarbes\Modelbase\Support\DatabaseErrorTranslator;
use Quatrebarbes\Modelbase\Tests\TestCase;
use Illuminate\Database\QueryException;
use PDOException;

/**
 * Les messages d'erreur utilisés ci-dessous ont été capturés en conditions
 * réelles (INSERT direct déclenchant chacune des 4 contraintes) contre
 * mysql 8.4 et pgsql 16 via l'environnement docker-compose du projet, et
 * contre le pilote sqlite via PDO — pas des messages inventés — cf.
 * DatabaseErrorTranslator pour le détail des limites par pilote.
 */
class DatabaseErrorTranslatorTest extends TestCase
{
    private function translator(): DatabaseErrorTranslator
    {
        return new DatabaseErrorTranslator;
    }

    /**
     * @param  array{0: string, 1: int, 2: string}  $errorInfo
     */
    private function queryException(array $errorInfo): QueryException
    {
        $previous = new PDOException($errorInfo[2], 0);
        $previous->errorInfo = $errorInfo;

        return new QueryException('primary', 'insert into "table" ...', [], $previous);
    }

    public function test_mysql_missing_value_is_translated_as_required(): void
    {
        $exception = $this->queryException(['23000', 1048, "Column 'sku' cannot be null"]);

        $result = $this->translator()->translate($exception, 'mysql', 'products');

        $this->assertSame(['column' => 'sku', 'rule' => 'required', 'message' => "Column 'sku' cannot be null"], $result);
    }

    public function test_mysql_omitted_value_without_default_is_translated_as_required(): void
    {
        $exception = $this->queryException(['HY000', 1364, "Field 'name' doesn't have a default value"]);

        $result = $this->translator()->translate($exception, 'mysql', 'categories');

        $this->assertSame('name', $result['column']);
        $this->assertSame('required', $result['rule']);
    }

    public function test_mysql_duplicate_entry_is_translated_as_unique_with_column_from_key_name(): void
    {
        $exception = $this->queryException(['23000', 1062, "Duplicate entry 'LRL-86341' for key 'products.products_sku_unique'"]);

        $result = $this->translator()->translate($exception, 'mysql', 'products');

        $this->assertSame('sku', $result['column']);
        $this->assertSame('unique', $result['rule']);
    }

    public function test_mysql_incorrect_value_is_translated_as_format(): void
    {
        $exception = $this->queryException(['HY000', 1366, "Incorrect integer value: 'notanumber' for column 'price_cents' at row 1"]);

        $result = $this->translator()->translate($exception, 'mysql', 'products');

        $this->assertSame('price_cents', $result['column']);
        $this->assertSame('format', $result['rule']);
    }

    public function test_mysql_child_row_is_translated_as_foreign_key(): void
    {
        $message = 'Cannot add or update a child row: a foreign key constraint fails '
            .'(`demo`.`products`, CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE)';
        $exception = $this->queryException(['23000', 1452, $message]);

        $result = $this->translator()->translate($exception, 'mysql', 'products');

        $this->assertSame('category_id', $result['column']);
        $this->assertSame('foreign_key', $result['rule']);
    }

    public function test_pgsql_not_null_violation_is_translated_as_required(): void
    {
        $message = "ERROR:  null value in column \"name\" of relation \"authors\" violates not-null constraint\n"
            .'DETAIL:  Failing row contains (7, null, zzz@example.com, null, null).';
        $exception = $this->queryException(['23502', 7, $message]);

        $result = $this->translator()->translate($exception, 'pgsql', 'authors');

        $this->assertSame('name', $result['column']);
        $this->assertSame('required', $result['rule']);
    }

    public function test_pgsql_unique_violation_is_translated_as_unique(): void
    {
        $message = "ERROR:  duplicate key value violates unique constraint \"authors_email_unique\"\n"
            .'DETAIL:  Key (email)=(aliya94@example.com) already exists.';
        $exception = $this->queryException(['23505', 7, $message]);

        $result = $this->translator()->translate($exception, 'pgsql', 'authors');

        $this->assertSame('email', $result['column']);
        $this->assertSame('unique', $result['rule']);
    }

    public function test_pgsql_foreign_key_violation_is_translated_as_foreign_key(): void
    {
        $message = "ERROR:  insert or update on table \"articles\" violates foreign key constraint \"articles_author_id_foreign\"\n"
            .'DETAIL:  Key (author_id)=(999999) is not present in table "authors".';
        $exception = $this->queryException(['23503', 7, $message]);

        $result = $this->translator()->translate($exception, 'pgsql', 'articles');

        $this->assertSame('author_id', $result['column']);
        $this->assertSame('foreign_key', $result['rule']);
    }

    public function test_pgsql_invalid_datetime_is_translated_as_format_without_column(): void
    {
        $message = 'ERROR:  invalid input syntax for type timestamp: "notadate"';
        $exception = $this->queryException(['22007', 7, $message]);

        $result = $this->translator()->translate($exception, 'pgsql', 'articles');

        $this->assertNull($result['column']);
        $this->assertSame('format', $result['rule']);
    }

    public function test_sqlite_not_null_violation_is_translated_as_required(): void
    {
        $exception = $this->queryException(['23000', 19, 'NOT NULL constraint failed: categories.name']);

        $result = $this->translator()->translate($exception, 'sqlite', 'categories');

        $this->assertSame('name', $result['column']);
        $this->assertSame('required', $result['rule']);
    }

    public function test_sqlite_unique_violation_is_translated_as_unique(): void
    {
        $exception = $this->queryException(['23000', 19, 'UNIQUE constraint failed: categories.name']);

        $result = $this->translator()->translate($exception, 'sqlite', 'categories');

        $this->assertSame('name', $result['column']);
        $this->assertSame('unique', $result['rule']);
    }

    public function test_sqlite_foreign_key_violation_is_translated_as_foreign_key_without_column(): void
    {
        $exception = $this->queryException(['23000', 19, 'FOREIGN KEY constraint failed']);

        $result = $this->translator()->translate($exception, 'sqlite', 'products');

        $this->assertNull($result['column']);
        $this->assertSame('foreign_key', $result['rule']);
    }

    public function test_unrecognized_driver_falls_back_to_unknown_with_raw_message(): void
    {
        $exception = $this->queryException(['HY000', 1, 'some sqlsrv-specific error']);

        $result = $this->translator()->translate($exception, 'sqlsrv', 'items');

        $this->assertNull($result['column']);
        $this->assertSame('unknown', $result['rule']);
        $this->assertSame('some sqlsrv-specific error', $result['message']);
    }
}
