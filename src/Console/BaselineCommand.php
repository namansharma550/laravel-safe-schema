<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RGInfotech\SafeMigrations\Context\ServerVersion;

class BaselineCommand extends Command
{
    protected $signature = 'migrate:lint:baseline
        {--connection= : The database connection to read table sizes from (defaults to the default connection)}
        {--output= : Path to write the baseline JSON file (defaults to config(safe-migrations.baseline_path))}';

    protected $description = 'Snapshot table sizes from a live database into a baseline file so migrate:lint can size-gate warnings without a production connection.';

    public function handle(): int
    {
        $connectionName = $this->option('connection');
        $connection = DB::connection(is_string($connectionName) && $connectionName !== '' ? $connectionName : null);

        try {
            $connection->getPdo();
        } catch (\Throwable $e) {
            $this->components->error("Could not connect to the database: {$e->getMessage()}");

            return self::FAILURE;
        }

        $driver = $connection->getDriverName();

        $tables = match ($driver) {
            'pgsql' => $this->readPostgresTableSizes($connection),
            'mysql' => $this->readMysqlTableSizes($connection),
            default => null,
        };

        if ($tables === null) {
            $this->components->error("migrate:lint:baseline does not support the '{$driver}' driver.");

            return self::FAILURE;
        }

        $serverVersion = $this->detectServerVersion($connection, $driver);

        $outputPath = $this->resolveOutputPath();

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'driver' => $driver,
            'server_version' => $serverVersion->raw(),
            'tables' => $tables,
        ];

        file_put_contents($outputPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->components->info(sprintf(
            'Wrote %d table size(s) to %s',
            count($tables),
            $outputPath,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function readPostgresTableSizes(Connection $connection): array
    {
        $rows = $connection->select(
            "SELECT c.relname AS name, c.reltuples::bigint AS estimate
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind IN ('r', 'p')
             AND n.nspname NOT IN ('pg_catalog', 'information_schema')
             ORDER BY c.relname",
        );

        $tables = [];

        foreach ($rows as $row) {
            $tables[$row->name] = max(0, (int) $row->estimate);
        }

        return $tables;
    }

    /**
     * @return array<string, int>
     */
    private function readMysqlTableSizes(Connection $connection): array
    {
        $rows = $connection->select(
            'SELECT table_name AS name, table_rows AS estimate
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_type = \'BASE TABLE\'
             ORDER BY table_name',
        );

        $tables = [];

        foreach ($rows as $row) {
            $tables[$row->name] = max(0, (int) $row->estimate);
        }

        return $tables;
    }

    private function detectServerVersion(Connection $connection, string $driver): ServerVersion
    {
        try {
            $raw = $connection->selectOne('SELECT version() AS version')?->version;

            if (! is_string($raw)) {
                return ServerVersion::unknown();
            }

            return match ($driver) {
                'pgsql' => ServerVersion::fromPostgresVersionString($raw),
                'mysql' => ServerVersion::fromMysqlVersionString($raw),
                default => ServerVersion::unknown(),
            };
        } catch (\Throwable) {
            return ServerVersion::unknown();
        }
    }

    private function resolveOutputPath(): string
    {
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            return $output;
        }

        $configured = config('safe-migrations.baseline_path');

        return is_string($configured) ? $configured : base_path('.safe-migrations-sizes.json');
    }
}
