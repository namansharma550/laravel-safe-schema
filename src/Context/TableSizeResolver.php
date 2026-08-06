<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Context;

use Illuminate\Database\Connection;

final class TableSizeResolver
{
    /**
     * @var array<string, int|null>
     */
    private array $liveLookupCache = [];

    /**
     * @param  array<string, int>  $baseline
     */
    public function __construct(
        private readonly array $baseline = [],
        private readonly ?Connection $connection = null,
    ) {}

    /**
     * @param  array<string, mixed>  $baselineFileContents
     */
    public static function fromBaselineFile(array $baselineFileContents, ?Connection $connection = null): self
    {
        /** @var array<string, int> $tables */
        $tables = $baselineFileContents['tables'] ?? [];

        return new self($tables, $connection);
    }

    /**
     * Resolution order: baseline file -> live connection -> unknown.
     * Returns null when the table's row count cannot be determined at all.
     * Callers must treat null as "warn, but say the size is unknown".
     */
    public function estimateRows(string $table): ?int
    {
        if (array_key_exists($table, $this->baseline)) {
            return $this->baseline[$table];
        }

        if ($this->connection === null) {
            return null;
        }

        if (array_key_exists($table, $this->liveLookupCache)) {
            return $this->liveLookupCache[$table];
        }

        return $this->liveLookupCache[$table] = $this->fetchLiveEstimate($table);
    }

    private function fetchLiveEstimate(string $table): ?int
    {
        try {
            return match ($this->connection?->getDriverName()) {
                'pgsql' => $this->fetchPostgresEstimate($table),
                'mysql' => $this->fetchMysqlEstimate($table),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetchPostgresEstimate(string $table): ?int
    {
        $row = $this->connection->selectOne(
            'SELECT reltuples::bigint AS estimate FROM pg_class WHERE relname = ?',
            [$table],
        );

        return $row !== null ? max(0, (int) $row->estimate) : null;
    }

    private function fetchMysqlEstimate(string $table): ?int
    {
        $row = $this->connection->selectOne(
            'SELECT table_rows AS estimate FROM information_schema.tables WHERE table_name = ? AND table_schema = DATABASE()',
            [$table],
        );

        return $row !== null ? max(0, (int) $row->estimate) : null;
    }
}
