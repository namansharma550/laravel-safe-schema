<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Tests\Fixtures\Support;

use Illuminate\Database\Migrations\Migration;
use RGInfotech\SafeSchema\SafeMigration;

/**
 * Exists purely so PHPStan has a concrete class to analyse SafeMigration's
 * trait body against - traits used nowhere are skipped by PHPStan entirely.
 * Also used directly by SafeMigrationTest to exercise the protected helpers.
 */
class MigrationUsingSafeMigration extends Migration
{
    use SafeMigration;

    /**
     * @param  list<string>  $columns
     */
    public function addIndexConcurrentlyPublic(string $table, array $columns, ?string $name = null): void
    {
        $this->addIndexConcurrently($table, $columns, $name);
    }

    public function addForeignKeyDeferredPublic(string $table, string $column, string $referencedTable, string $referencedColumn = 'id'): void
    {
        $this->addForeignKeyDeferred($table, $column, $referencedTable, $referencedColumn);
    }

    public function setNotNullSafelyPublic(string $table, string $column, string $columnDefinition = ''): void
    {
        $this->setNotNullSafely($table, $column, $columnDefinition);
    }

    /**
     * Exposes the retry loop directly so its attempt-count/backoff/give-up
     * behaviour can be tested deterministically with a fake attempt
     * callable, without needing a real blocked lock.
     *
     * @param  callable(int): void  $attempt
     * @param  callable(\Illuminate\Database\QueryException): bool  $isLockTimeoutError
     */
    public function withLockRetryPublic(int $maxAttempts, int $initialTimeout, callable $attempt, callable $isLockTimeoutError): void
    {
        $this->withLockRetry($maxAttempts, $initialTimeout, $attempt, $isLockTimeoutError);
    }
}
