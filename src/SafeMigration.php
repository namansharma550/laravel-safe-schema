<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Runtime helpers for writing migrations that use Postgres/MySQL's actual
 * safe DDL sequences instead of the naive, lock-holding version. Unlike the
 * rest of the package (static analysis only), these helpers execute real
 * DDL against a live connection.
 */
trait SafeMigration
{
    /**
     * Adds an index without taking a lock that blocks writes.
     *
     * Postgres: CREATE INDEX CONCURRENTLY. Requires the migration to declare
     * `public $withinTransaction = false;` - Postgres hard-errors if
     * CONCURRENTLY runs inside a transaction block.
     *
     * MySQL: ALTER TABLE ... ADD INDEX ... ALGORITHM=INPLACE, LOCK=NONE.
     * InnoDB builds the index without copying the table and only takes a
     * brief metadata lock at the start and end.
     */
    /**
     * @param  list<string>  $columns
     */
    protected function addIndexConcurrently(string $table, array $columns, ?string $name = null): void
    {
        $name ??= 'idx_'.$table.'_'.implode('_', $columns);
        $columnList = implode(', ', $columns);

        match ($this->safeMigrationDriver()) {
            'pgsql' => $this->pgsqlAddIndexConcurrently($name, $table, $columnList),
            'mysql' => $this->runWithMysqlLockRetry(
                "ALTER TABLE {$table} ADD INDEX {$name} ({$columnList}), ALGORITHM=INPLACE, LOCK=NONE",
            ),
            default => $this->unsupportedDriver('addIndexConcurrently'),
        };
    }

    /**
     * If CREATE INDEX CONCURRENTLY fails or times out partway, Postgres
     * leaves an INVALID index behind under that name rather than cleaning
     * up automatically. A bare retry would then fail with "relation already
     * exists" - a confusing error that masks the real lock-timeout retry -
     * so each attempt first drops a leftover invalid index of this name, if
     * one exists, before trying again.
     */
    private function pgsqlAddIndexConcurrently(string $name, string $table, string $columnList): void
    {
        $this->runWithPgsqlLockRetry(
            "CREATE INDEX CONCURRENTLY {$name} ON {$table} ({$columnList})",
            beforeEachAttempt: fn () => $this->dropInvalidPostgresIndexIfExists($name),
        );
    }

    private function dropInvalidPostgresIndexIfExists(string $name): void
    {
        $row = DB::selectOne(
            'SELECT indisvalid FROM pg_index '
            .'JOIN pg_class ON pg_class.oid = pg_index.indexrelid '
            .'WHERE pg_class.relname = ?',
            [$name],
        );

        if ($row !== null && ! $row->indisvalid) {
            DB::statement("DROP INDEX CONCURRENTLY {$name}");
        }
    }

    /**
     * Adds a foreign key without taking a lock that validates every existing
     * row while holding it.
     *
     * Postgres: ADD CONSTRAINT ... NOT VALID, then a separate
     * VALIDATE CONSTRAINT statement. NOT VALID skips the initial scan and
     * only takes a brief lock; VALIDATE CONSTRAINT then scans the table
     * without blocking writes.
     *
     * MySQL: has no NOT VALID/VALIDATE split - InnoDB validates a new
     * foreign key in one pass. ALGORITHM=INPLACE is NOT supported for
     * adding a foreign key (confirmed against a real server: MySQL/MariaDB
     * reject it with "ALGORITHM=INPLACE is not supported... Try
     * ALGORITHM=COPY", and COPY does a full table rewrite - the opposite of
     * what this helper is for). This runs the plain ALTER TABLE with no
     * algorithm hint, letting the server pick its own strategy, which for a
     * simple foreign key addition on InnoDB is a fast, non-copying
     * metadata operation.
     */
    protected function addForeignKeyDeferred(string $table, string $column, string $referencedTable, string $referencedColumn = 'id'): void
    {
        $constraintName = "{$table}_{$column}_foreign";

        match ($this->safeMigrationDriver()) {
            'pgsql' => (function () use ($table, $column, $referencedTable, $referencedColumn, $constraintName): void {
                $this->runWithPgsqlLockRetry(
                    "ALTER TABLE {$table} ADD CONSTRAINT {$constraintName} "
                    ."FOREIGN KEY ({$column}) REFERENCES {$referencedTable} ({$referencedColumn}) NOT VALID",
                );
                $this->runWithPgsqlLockRetry(
                    "ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraintName}",
                );
            })(),
            'mysql' => $this->runWithMysqlLockRetry(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraintName} "
                ."FOREIGN KEY ({$column}) REFERENCES {$referencedTable} ({$referencedColumn})",
            ),
            default => $this->unsupportedDriver('addForeignKeyDeferred'),
        };
    }

    /**
     * Sets a column NOT NULL without the full-table rescan a plain
     * SET NOT NULL forces.
     *
     * Postgres 12+: add a validated CHECK (col IS NOT NULL) constraint
     * first (NOT VALID + VALIDATE CONSTRAINT, same pattern as above), then
     * SET NOT NULL - the planner can prove no row is null from the
     * already-validated constraint and skips its own rescan. The temporary
     * CHECK constraint is dropped afterward since the column's own NOT NULL
     * already enforces it. Before PG 12 this optimisation doesn't exist, so
     * this falls back to a plain SET NOT NULL there.
     *
     * MySQL: MODIFY COLUMN ... NOT NULL is a single ALGORITHM=INPLACE
     * operation with no equivalent validated-constraint trick - runs with
     * LOCK=NONE where the column type allows it.
     */
    protected function setNotNullSafely(string $table, string $column, string $columnDefinition = ''): void
    {
        match ($this->safeMigrationDriver()) {
            'pgsql' => $this->pgsqlSetNotNullSafely($table, $column),
            'mysql' => $this->runWithMysqlLockRetry(
                "ALTER TABLE {$table} MODIFY COLUMN {$column} {$columnDefinition} NOT NULL, ALGORITHM=INPLACE, LOCK=NONE",
            ),
            default => $this->unsupportedDriver('setNotNullSafely'),
        };
    }

    private function pgsqlSetNotNullSafely(string $table, string $column): void
    {
        if (! $this->safeMigrationServerVersionAtLeast(12)) {
            $this->runWithPgsqlLockRetry("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");

            return;
        }

        $constraintName = "{$table}_{$column}_not_null_check";

        $this->runWithPgsqlLockRetry(
            "ALTER TABLE {$table} ADD CONSTRAINT {$constraintName} CHECK ({$column} IS NOT NULL) NOT VALID",
        );
        $this->runWithPgsqlLockRetry(
            "ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraintName}",
        );
        $this->runWithPgsqlLockRetry(
            "ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL",
        );
        $this->runWithPgsqlLockRetry(
            "ALTER TABLE {$table} DROP CONSTRAINT {$constraintName}",
        );
    }

    /**
     * Runs $sql with a session-scoped lock_timeout, retrying with
     * exponential backoff when Postgres raises 55P03 (lock_not_available) -
     * i.e. the statement queued behind another lock holder longer than the
     * timeout.
     *
     * This deliberately does NOT wrap the statement in a transaction. Some
     * of these statements (CREATE INDEX CONCURRENTLY) cannot run inside a
     * transaction block at all - Postgres hard-errors. SET (session-scoped,
     * as opposed to SET LOCAL) doesn't require one, and each DB::statement()
     * already runs in its own implicit transaction under PDO's default
     * autocommit mode.
     */
    private function runWithPgsqlLockRetry(string $sql, ?callable $beforeEachAttempt = null, int $maxAttempts = 5, int $initialTimeoutMs = 2000): void
    {
        $this->withLockRetry($maxAttempts, $initialTimeoutMs, function (int $timeoutMs) use ($sql, $beforeEachAttempt): void {
            if ($beforeEachAttempt !== null) {
                $beforeEachAttempt();
            }
            DB::statement("SET lock_timeout = '{$timeoutMs}ms'");
            DB::statement($sql);
        }, isLockTimeoutError: fn (QueryException $e): bool => ($e->errorInfo[0] ?? null) === '55P03');
    }

    /**
     * Runs $sql with SET SESSION lock_wait_timeout, retrying with
     * exponential backoff when MySQL raises error 1205
     * (ER_LOCK_WAIT_TIMEOUT) - the statement couldn't acquire its metadata
     * lock within the timeout.
     */
    private function runWithMysqlLockRetry(string $sql, int $maxAttempts = 5, int $initialTimeoutSeconds = 2): void
    {
        $this->withLockRetry($maxAttempts, $initialTimeoutSeconds, function (int $timeoutSeconds) use ($sql): void {
            DB::statement("SET SESSION lock_wait_timeout = {$timeoutSeconds}");
            DB::statement($sql);
        }, isLockTimeoutError: fn (QueryException $e): bool => ($e->errorInfo[1] ?? null) === 1205);
    }

    /**
     * @param  callable(int): void  $attempt  Receives the timeout value to use for this attempt.
     * @param  callable(QueryException): bool  $isLockTimeoutError
     */
    private function withLockRetry(int $maxAttempts, int $initialTimeout, callable $attempt, callable $isLockTimeoutError): void
    {
        $timeout = $initialTimeout;

        for ($attemptNumber = 1; $attemptNumber <= $maxAttempts; $attemptNumber++) {
            try {
                $attempt($timeout);

                return;
            } catch (QueryException $e) {
                if (! $isLockTimeoutError($e) || $attemptNumber === $maxAttempts) {
                    throw $e;
                }

                usleep((2 ** $attemptNumber) * 100_000);
                $timeout *= 2;
            }
        }
    }

    private function safeMigrationDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    private function safeMigrationServerVersionAtLeast(int $major): bool
    {
        $raw = DB::selectOne('SELECT version() AS version')?->version;

        if (! is_string($raw) || preg_match('/PostgreSQL (\d+)/', $raw, $matches) !== 1) {
            // Unknown version: assume the conservative (older) behaviour.
            return false;
        }

        return (int) $matches[1] >= $major;
    }

    private function unsupportedDriver(string $method): never
    {
        throw new \RuntimeException(
            "{$method}() does not support the '{$this->safeMigrationDriver()}' driver. "
            .'Supported drivers: pgsql, mysql.',
        );
    }
}
