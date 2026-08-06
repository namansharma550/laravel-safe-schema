<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use RGInfotech\SafeMigrations\Tests\Fixtures\Support\MigrationUsingSafeMigration;

function fakeLockTimeoutException(string $sqlstate): QueryException
{
    $pdoException = new PDOException('lock timeout');
    $pdoException->errorInfo = [$sqlstate, 1205, 'Lock wait timeout exceeded'];

    return new QueryException('test', 'SELECT 1', [], $pdoException);
}

function fakeUnrelatedException(): QueryException
{
    $pdoException = new PDOException('syntax error');
    $pdoException->errorInfo = ['42601', 1064, 'You have an error in your SQL syntax'];

    return new QueryException('test', 'SELECT 1', [], $pdoException);
}

it('succeeds on the first attempt without retrying when there is no error', function () {
    $migration = new MigrationUsingSafeMigration;
    $calls = 0;

    $migration->withLockRetryPublic(
        maxAttempts: 5,
        initialTimeout: 1,
        attempt: function () use (&$calls) {
            $calls++;
        },
        isLockTimeoutError: fn () => true,
    );

    expect($calls)->toBe(1);
});

it('retries on a lock-timeout error and succeeds once the attempt stops failing', function () {
    $migration = new MigrationUsingSafeMigration;
    $calls = 0;

    $migration->withLockRetryPublic(
        maxAttempts: 5,
        initialTimeout: 1,
        attempt: function () use (&$calls) {
            $calls++;

            if ($calls < 3) {
                throw fakeLockTimeoutException('55P03');
            }
        },
        isLockTimeoutError: fn (QueryException $e) => ($e->errorInfo[0] ?? null) === '55P03',
    );

    expect($calls)->toBe(3);
});

it('gives up and rethrows after maxAttempts lock-timeout failures', function () {
    $migration = new MigrationUsingSafeMigration;
    $calls = 0;

    $threw = false;

    try {
        $migration->withLockRetryPublic(
            maxAttempts: 3,
            initialTimeout: 1,
            attempt: function () use (&$calls) {
                $calls++;

                throw fakeLockTimeoutException('55P03');
            },
            isLockTimeoutError: fn (QueryException $e) => ($e->errorInfo[0] ?? null) === '55P03',
        );
    } catch (QueryException) {
        $threw = true;
    }

    expect($threw)->toBeTrue()
        ->and($calls)->toBe(3);
});

it('rethrows immediately without retrying on a non-lock-timeout error', function () {
    $migration = new MigrationUsingSafeMigration;
    $calls = 0;

    $threw = false;

    try {
        $migration->withLockRetryPublic(
            maxAttempts: 5,
            initialTimeout: 1,
            attempt: function () use (&$calls) {
                $calls++;

                throw fakeUnrelatedException();
            },
            isLockTimeoutError: fn (QueryException $e) => ($e->errorInfo[0] ?? null) === '55P03',
        );
    } catch (QueryException) {
        $threw = true;
    }

    expect($threw)->toBeTrue()
        ->and($calls)->toBe(1);
});
