<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Proves the danger claim behind the index-not-concurrent rule against a
 * real Postgres server: a plain CREATE INDEX takes a lock that blocks
 * concurrent writers, while CREATE INDEX CONCURRENTLY does not.
 *
 * Requires SAFE_MIGRATIONS_TEST_PGSQL_* env vars pointing at a real
 * Postgres instance (see .github/workflows/tests.yml). Skips itself when
 * that connection isn't reachable, so it never fails a run that simply
 * doesn't have Postgres available (e.g. running the Unit/Feature suite
 * locally without a database).
 */
function pgsqlIntegrationConnection(): ?Connection
{
    config(['database.connections.pgsql_integration' => [
        'driver' => 'pgsql',
        'host' => env('SAFE_MIGRATIONS_TEST_PGSQL_HOST', '127.0.0.1'),
        'port' => env('SAFE_MIGRATIONS_TEST_PGSQL_PORT', '5432'),
        'database' => env('SAFE_MIGRATIONS_TEST_PGSQL_DATABASE', 'safe_migrations_test'),
        'username' => env('SAFE_MIGRATIONS_TEST_PGSQL_USERNAME', 'postgres'),
        'password' => env('SAFE_MIGRATIONS_TEST_PGSQL_PASSWORD', 'postgres'),
    ]]);

    try {
        $connection = DB::connection('pgsql_integration');
        $connection->getPdo();

        return $connection;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Opens a second, independent PDO connection (not going through Laravel's
 * connection pool, so it can hold an open transaction while the main
 * connection runs DDL against the same table).
 */
function openSecondPostgresConnection(): PDO
{
    $host = env('SAFE_MIGRATIONS_TEST_PGSQL_HOST', '127.0.0.1');
    $port = env('SAFE_MIGRATIONS_TEST_PGSQL_PORT', '5432');
    $database = env('SAFE_MIGRATIONS_TEST_PGSQL_DATABASE', 'safe_migrations_test');
    $username = env('SAFE_MIGRATIONS_TEST_PGSQL_USERNAME', 'postgres');
    $password = env('SAFE_MIGRATIONS_TEST_PGSQL_PASSWORD', 'postgres');

    return new PDO(
        "pgsql:host={$host};port={$port};dbname={$database}",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

beforeEach(function () {
    $this->connection = pgsqlIntegrationConnection();

    if ($this->connection === null) {
        test()->markTestSkipped('No Postgres integration database reachable.');
    }

    $this->connection->statement('DROP TABLE IF EXISTS lock_probe');
    $this->connection->statement('CREATE TABLE lock_probe (id SERIAL PRIMARY KEY, status TEXT)');
    $this->connection->statement("INSERT INTO lock_probe (status) SELECT 'pending' FROM generate_series(1, 1000)");
});

afterEach(function () {
    $this->connection?->statement('DROP TABLE IF EXISTS lock_probe');
});

it('shows a plain CREATE INDEX is blocked behind a concurrent writer, holding a table-level lock', function () {
    $writer = openSecondPostgresConnection();
    $writer->beginTransaction();
    // An UPDATE without COMMIT holds a RowExclusiveLock on the table for
    // the life of the transaction - exactly the in-flight-query scenario
    // the rule warns about.
    $writer->exec("UPDATE lock_probe SET status = 'updated' WHERE id = 1");

    // Run the plain CREATE INDEX on a separate PDO connection in the
    // background (via a forked PHP process) so it can genuinely block
    // without freezing this test process.
    $indexPdo = openSecondPostgresConnection();
    $indexPdo->setAttribute(PDO::ATTR_TIMEOUT, 5);

    $blocked = false;

    try {
        // lock_timeout forces a fast, deterministic failure instead of an
        // indefinite hang if CREATE INDEX is indeed queued behind the
        // writer's RowExclusiveLock.
        $indexPdo->exec('SET lock_timeout = 500');
        $indexPdo->exec('CREATE INDEX idx_lock_probe_status ON lock_probe (status)');
    } catch (PDOException $e) {
        // Postgres error 55P03 = lock_not_available, raised when
        // lock_timeout is exceeded waiting for a lock.
        $blocked = str_contains($e->getMessage(), 'lock timeout')
            || str_contains($e->getMessage(), '55P03');
    }

    $writer->rollBack();

    expect($blocked)->toBeTrue(
        'Expected a plain CREATE INDEX to queue behind the writer\'s row lock and hit lock_timeout, '
        .'which is the exact failure mode index-not-concurrent warns about.'
    );
});

it('shows CREATE INDEX CONCURRENTLY is NOT blocked by a concurrent writer', function () {
    $writer = openSecondPostgresConnection();
    $writer->beginTransaction();
    $writer->exec("UPDATE lock_probe SET status = 'updated' WHERE id = 1");

    $indexPdo = openSecondPostgresConnection();
    // CREATE INDEX CONCURRENTLY cannot run inside a transaction block, and
    // PDO connections default to autocommit, so no explicit transaction
    // handling is needed here - each exec() is its own implicit transaction.
    $indexPdo->exec('SET lock_timeout = 500');

    $blocked = false;

    try {
        $indexPdo->exec('CREATE INDEX CONCURRENTLY idx_lock_probe_status_c ON lock_probe (status)');
    } catch (PDOException $e) {
        $blocked = str_contains($e->getMessage(), 'lock timeout')
            || str_contains($e->getMessage(), '55P03');
    }

    $writer->rollBack();

    expect($blocked)->toBeFalse(
        'CREATE INDEX CONCURRENTLY should not be blocked by a writer holding a row-level lock - '
        .'it only takes ShareUpdateExclusiveLock, which does not conflict with RowExclusiveLock.'
    );
});
