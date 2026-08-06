<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RGInfotech\SafeSchema\Context\ServerVersion;
use RGInfotech\SafeSchema\Context\TableSizeResolver;

/**
 * These tests hit a real local MySQL/MariaDB server (via XAMPP) rather than
 * mocking the connection, since a fake PDO response can't prove the actual
 * SQL against information_schema is correct.
 */
function skipIfNoLocalMysql(): void
{
    config(['database.default' => 'mysql_test']);
    config(['database.connections.mysql_test' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'safe_migrations_test',
        'username' => 'root',
        'password' => '',
    ]]);

    try {
        DB::connection('mysql_test')->getPdo();
    } catch (Throwable $e) {
        test()->markTestSkipped('No local MySQL/MariaDB reachable: '.$e->getMessage());
    }
}

it('detects the live MySQL/MariaDB server version', function () {
    skipIfNoLocalMysql();

    $raw = DB::connection('mysql_test')->selectOne('SELECT version() AS version')->version;
    $version = ServerVersion::fromMysqlVersionString($raw);

    expect($version->isKnown())->toBeTrue()
        ->and($version->isAtLeast(5))->toBeTrue()
        ->and($version->isAtLeast(99))->toBeFalse();
});

it('reads a live row-count estimate for an existing table', function () {
    skipIfNoLocalMysql();

    $connection = DB::connection('mysql_test');
    $connection->statement('DROP TABLE IF EXISTS safe_migrations_probe');
    $connection->statement('CREATE TABLE safe_migrations_probe (id INT PRIMARY KEY)');
    $connection->statement('INSERT INTO safe_migrations_probe VALUES (1), (2), (3)');
    // information_schema.tables row counts are estimates refreshed by ANALYZE,
    // not a live COUNT(*) - force a refresh so the estimate is non-null.
    $connection->statement('ANALYZE TABLE safe_migrations_probe');

    $resolver = TableSizeResolver::fromBaselineFile([], $connection);
    $estimate = $resolver->estimateRows('safe_migrations_probe');

    expect($estimate)->not->toBeNull()
        ->and($estimate)->toBeGreaterThanOrEqual(0);

    $connection->statement('DROP TABLE safe_migrations_probe');
});

it('returns null for a table that does not exist, without crashing', function () {
    skipIfNoLocalMysql();

    $resolver = TableSizeResolver::fromBaselineFile([], DB::connection('mysql_test'));

    expect($resolver->estimateRows('table_that_does_not_exist_at_all'))->toBeNull();
});

it('prefers the baseline file over a live lookup when both are available', function () {
    skipIfNoLocalMysql();

    $connection = DB::connection('mysql_test');
    $connection->statement('DROP TABLE IF EXISTS safe_migrations_probe');
    $connection->statement('CREATE TABLE safe_migrations_probe (id INT PRIMARY KEY)');

    $resolver = TableSizeResolver::fromBaselineFile(['tables' => ['safe_migrations_probe' => 8_400_000]], $connection);

    expect($resolver->estimateRows('safe_migrations_probe'))->toBe(8_400_000);

    $connection->statement('DROP TABLE safe_migrations_probe');
});
