<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RGInfotech\SafeMigrations\Tests\Fixtures\Support\MigrationUsingSafeMigration;

/**
 * Proves SafeMigration's helpers actually produce the schema changes they
 * claim, against a real MySQL/MariaDB server.
 */
beforeEach(function () {
    config(['database.connections.mysql_integration' => [
        'driver' => 'mysql',
        'host' => env('SAFE_MIGRATIONS_TEST_MYSQL_HOST', '127.0.0.1'),
        'port' => env('SAFE_MIGRATIONS_TEST_MYSQL_PORT', '3306'),
        'database' => env('SAFE_MIGRATIONS_TEST_MYSQL_DATABASE', 'safe_migrations_test'),
        'username' => env('SAFE_MIGRATIONS_TEST_MYSQL_USERNAME', 'root'),
        'password' => env('SAFE_MIGRATIONS_TEST_MYSQL_PASSWORD', ''),
    ]]);
    config(['database.default' => 'mysql_integration']);

    try {
        DB::connection('mysql_integration')->getPdo();
    } catch (Throwable $e) {
        test()->markTestSkipped('No MySQL/MariaDB integration database reachable: '.$e->getMessage());
    }

    DB::statement('DROP TABLE IF EXISTS safe_migration_orders');
    DB::statement('DROP TABLE IF EXISTS safe_migration_users');
    DB::statement('CREATE TABLE safe_migration_users (id INT PRIMARY KEY AUTO_INCREMENT)');
    DB::statement('CREATE TABLE safe_migration_orders (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT, status VARCHAR(50))');

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

it('addIndexConcurrently actually creates a usable index', function () {
    $this->migration->addIndexConcurrentlyPublic('safe_migration_orders', ['status'], 'idx_test_status');

    $indexes = DB::select('SHOW INDEX FROM safe_migration_orders WHERE Key_name = ?', ['idx_test_status']);

    expect($indexes)->not->toBeEmpty();
});

it('addForeignKeyDeferred actually creates an enforced foreign key', function () {
    $this->migration->addForeignKeyDeferredPublic('safe_migration_orders', 'user_id', 'safe_migration_users');

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

it('setNotNullSafely actually enforces NOT NULL', function () {
    $this->migration->setNotNullSafelyPublic('safe_migration_orders', 'status', 'VARCHAR(50)');

    $rejected = false;

    try {
        DB::table('safe_migration_orders')->insert(['id' => 1, 'user_id' => 1, 'status' => null]);
    } catch (Throwable) {
        $rejected = true;
    }

    expect($rejected)->toBeTrue('Expected NOT NULL to reject a null status.');
});
