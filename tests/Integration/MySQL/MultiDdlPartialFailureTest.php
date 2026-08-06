<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Proves the danger claim behind mysql-multi-ddl against a real
 * MySQL/MariaDB server: MySQL has no transactional DDL, so when the second
 * of two DDL statements in a migration fails, the first has already
 * committed and stays committed - there is no automatic rollback.
 *
 * Requires SAFE_MIGRATIONS_TEST_MYSQL_* env vars pointing at a real
 * MySQL/MariaDB instance (see .github/workflows/tests.yml). Skips itself
 * when that connection isn't reachable.
 */
function mysqlIntegrationConnection(): ?Connection
{
    config(['database.connections.mysql_integration' => [
        'driver' => 'mysql',
        'host' => env('SAFE_MIGRATIONS_TEST_MYSQL_HOST', '127.0.0.1'),
        'port' => env('SAFE_MIGRATIONS_TEST_MYSQL_PORT', '3306'),
        'database' => env('SAFE_MIGRATIONS_TEST_MYSQL_DATABASE', 'safe_migrations_test'),
        'username' => env('SAFE_MIGRATIONS_TEST_MYSQL_USERNAME', 'root'),
        'password' => env('SAFE_MIGRATIONS_TEST_MYSQL_PASSWORD', ''),
    ]]);

    try {
        $connection = DB::connection('mysql_integration');
        $connection->getPdo();

        return $connection;
    } catch (Throwable) {
        return null;
    }
}

beforeEach(function () {
    $this->connection = mysqlIntegrationConnection();

    if ($this->connection === null) {
        test()->markTestSkipped('No MySQL/MariaDB integration database reachable.');
    }

    $this->connection->statement('DROP TABLE IF EXISTS multi_ddl_probe');
});

afterEach(function () {
    $this->connection?->statement('DROP TABLE IF EXISTS multi_ddl_probe');
});

it('leaves the first DDL statement committed when the second one fails', function () {
    // Statement 1: succeeds and creates the table.
    $this->connection->statement('CREATE TABLE multi_ddl_probe (id INT PRIMARY KEY, status VARCHAR(50))');

    // Statement 2: deliberately fails - adding a column that already exists.
    $failed = false;

    try {
        $this->connection->statement('ALTER TABLE multi_ddl_probe ADD COLUMN status VARCHAR(50)');
    } catch (Throwable) {
        $failed = true;
    }

    expect($failed)->toBeTrue('Expected the duplicate-column ALTER TABLE to fail.');

    // If MySQL had transactional DDL like Postgres, a failure partway
    // through a multi-statement migration would roll back statement 1 as
    // well. It does not: the table from statement 1 is still there.
    $tableExists = $this->connection->getSchemaBuilder()->hasTable('multi_ddl_probe');

    expect($tableExists)->toBeTrue(
        'Expected the table from the first DDL statement to still exist after the '
        .'second statement failed - this is exactly the partial-schema state '
        .'mysql-multi-ddl warns about.'
    );
});
