<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RGInfotech\SafeMigrations\Tests\Fixtures\Support\MigrationUsingSafeMigration;

/**
 * Proves SafeMigration's helpers actually produce the schema changes they
 * claim, against a real Postgres server.
 */
beforeEach(function () {
    config(['database.connections.pgsql_integration' => [
        'driver' => 'pgsql',
        'host' => env('SAFE_MIGRATIONS_TEST_PGSQL_HOST', '127.0.0.1'),
        'port' => env('SAFE_MIGRATIONS_TEST_PGSQL_PORT', '5432'),
        'database' => env('SAFE_MIGRATIONS_TEST_PGSQL_DATABASE', 'safe_migrations_test'),
        'username' => env('SAFE_MIGRATIONS_TEST_PGSQL_USERNAME', 'postgres'),
        'password' => env('SAFE_MIGRATIONS_TEST_PGSQL_PASSWORD', 'postgres'),
    ]]);
    config(['database.default' => 'pgsql_integration']);

    // Laravel connections are lazy - getPdo() alone does not attempt to
    // actually connect until the first real query runs. Force an eager
    // connection attempt here so an unreachable database is caught and
    // skipped cleanly, instead of surfacing as a confusing failure on the
    // first DB::statement() call below.
    try {
        DB::connection('pgsql_integration')->select('SELECT 1');
    } catch (Throwable $e) {
        test()->markTestSkipped('No Postgres integration database reachable: '.$e->getMessage());
    }

    DB::statement('DROP TABLE IF EXISTS safe_migration_orders');
    DB::statement('DROP TABLE IF EXISTS safe_migration_users');
    DB::statement('CREATE TABLE safe_migration_users (id SERIAL PRIMARY KEY)');
    DB::statement('CREATE TABLE safe_migration_orders (id SERIAL PRIMARY KEY, user_id INT, status TEXT)');

    $this->migration = new MigrationUsingSafeMigration;
});

afterEach(function () {
    try {
        DB::statement('DROP TABLE IF EXISTS safe_migration_orders');
        DB::statement('DROP TABLE IF EXISTS safe_migration_users');
    } catch (Throwable) {
        // beforeEach already skipped this test because no database was
        // reachable - nothing to clean up.
    }
});

it('addIndexConcurrently actually creates a valid index', function () {
    $this->migration->addIndexConcurrentlyPublic('safe_migration_orders', ['status'], 'idx_test_status');

    $row = DB::selectOne(
        'SELECT indisvalid FROM pg_index '
        .'JOIN pg_class ON pg_class.oid = pg_index.indexrelid '
        .'WHERE pg_class.relname = ?',
        ['idx_test_status'],
    );

    expect($row)->not->toBeNull()
        ->and($row->indisvalid)->toBeTrue();
});

it('addForeignKeyDeferred actually creates a validated, enforced foreign key', function () {
    $this->migration->addForeignKeyDeferredPublic('safe_migration_orders', 'user_id', 'safe_migration_users');

    $row = DB::selectOne(
        'SELECT convalidated FROM pg_constraint WHERE conname = ?',
        ['safe_migration_orders_user_id_foreign'],
    );

    expect($row)->not->toBeNull()
        ->and($row->convalidated)->toBeTrue();

    DB::table('safe_migration_users')->insert(['id' => 1]);
    DB::table('safe_migration_orders')->insert(['id' => 1, 'user_id' => 1, 'status' => 'ok']);

    $rejected = false;

    try {
        DB::table('safe_migration_orders')->insert(['id' => 2, 'user_id' => 999, 'status' => 'ok']);
    } catch (Throwable) {
        $rejected = true;
    }

    expect($rejected)->toBeTrue(
        'Expected the foreign key to reject a reference to a non-existent user.'
    );
});

it('setNotNullSafely actually enforces NOT NULL and cleans up its temporary constraint', function () {
    $this->migration->setNotNullSafelyPublic('safe_migration_orders', 'status');

    $rejected = false;

    try {
        DB::table('safe_migration_orders')->insert(['id' => 1, 'user_id' => 1, 'status' => null]);
    } catch (Throwable) {
        $rejected = true;
    }

    expect($rejected)->toBeTrue('Expected NOT NULL to reject a null status.');

    $leftoverConstraint = DB::selectOne(
        'SELECT 1 FROM pg_constraint WHERE conname = ?',
        ['safe_migration_orders_status_not_null_check'],
    );

    expect($leftoverConstraint)->toBeNull(
        'Expected the temporary CHECK constraint to be dropped once SET NOT NULL succeeded.'
    );
});
