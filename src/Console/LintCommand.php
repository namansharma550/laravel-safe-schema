<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RGInfotech\SafeSchema\Analysis\MigrationParser;
use RGInfotech\SafeSchema\Context\LintContext;
use RGInfotech\SafeSchema\Context\ServerVersion;
use RGInfotech\SafeSchema\Context\TableSizeResolver;
use RGInfotech\SafeSchema\Output\ConsoleReporter;
use RGInfotech\SafeSchema\Output\GithubReporter;
use RGInfotech\SafeSchema\Output\JsonReporter;
use RGInfotech\SafeSchema\Results\LintResult;
use RGInfotech\SafeSchema\Results\Violation;
use RGInfotech\SafeSchema\Rules\RuleRegistry;
use RGInfotech\SafeSchema\Support\IgnoreAnnotations;
use Symfony\Component\Process\Process;

class LintCommand extends Command
{
    protected $signature = 'migrate:lint
        {--path=database/migrations : Directory to scan for migration files}
        {--new-only : Only lint migrations not yet recorded in the migrations table}
        {--since= : Only lint files changed since this git ref, e.g. origin/main}
        {--strict : Exit non-zero when violations are found}
        {--format=console : Output format: console, github, json}';

    protected $description = 'Lint Laravel migrations for operations that lock production tables unsafely.';

    public function __construct(private readonly RuleRegistry $rules)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rawPath = (string) $this->option('path');
        $path = $this->isAbsolutePath($rawPath) ? $rawPath : base_path($rawPath);

        if (! is_dir($path)) {
            $this->components->warn("Migration path does not exist: {$path}");

            return self::SUCCESS;
        }

        $driver = config('database.default') !== null
            ? (string) config('database.connections.'.config('database.default').'.driver', 'pgsql')
            : 'pgsql';

        if ($driver === 'sqlite') {
            $this->components->info('safe-schema does not analyse SQLite migrations. Skipping.');

            return self::SUCCESS;
        }

        $files = $this->discoverFiles($path);
        $files = $this->applyNewOnlyFilter($files);
        $files = $this->applySinceFilter($files, $path);

        $context = $this->buildContext($driver);
        $parser = new MigrationParser;

        $violations = [];
        $skipped = [];
        $scanned = 0;

        foreach ($files as $file) {
            $scanned++;

            $migrationFile = $parser->parse($file, function (string $warning) use (&$skipped, $file): void {
                $skipped[] = $file;
                $this->components->warn($warning);
            });

            if ($migrationFile === null) {
                continue;
            }

            $ignores = IgnoreAnnotations::scan($file);

            if ($ignores->ignoresWholeFile()) {
                continue;
            }

            foreach ($migrationFile->operations as $operation) {
                if ($operation->tableCreatedInSameFile) {
                    continue;
                }

                foreach ($this->rules->forDriver($driver) as $rule) {
                    if (! $context->isRuleEnabled($rule->id())) {
                        continue;
                    }

                    if (! $rule->appliesTo($operation)) {
                        continue;
                    }

                    if ($ignores->ignores($rule->id(), $operation->line)) {
                        continue;
                    }

                    $violation = $rule->check($operation, $context);

                    if ($violation instanceof Violation) {
                        $violations[] = $violation->withFile($file);
                    }
                }
            }
        }

        $result = new LintResult(
            violations: $violations,
            filesScanned: $scanned,
            filesSkippedAlreadyMigrated: 0,
            skippedFiles: $skipped,
        );

        $this->render($result);

        $strict = (bool) $this->option('strict') || (bool) config('safe-schema.strict', false);

        return ($strict && $result->hasViolations()) ? self::FAILURE : self::SUCCESS;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * @return list<string>
     */
    private function discoverFiles(string $path): array
    {
        $files = glob(rtrim($path, '/').'/*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function applyNewOnlyFilter(array $files): array
    {
        if (! $this->option('new-only')) {
            return $files;
        }

        try {
            if (! DB::connection()->getSchemaBuilder()->hasTable('migrations')) {
                $this->components->warn('--new-only requested but no `migrations` table found; linting all files.');

                return $files;
            }

            $applied = DB::table('migrations')->pluck('migration')->all();
        } catch (\Throwable $e) {
            $this->components->warn("--new-only requested but the database is unreachable ({$e->getMessage()}); linting all files.");

            return $files;
        }

        return array_values(array_filter(
            $files,
            fn (string $file): bool => ! in_array(pathinfo($file, PATHINFO_FILENAME), $applied, true),
        ));
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function applySinceFilter(array $files, string $migrationsPath): array
    {
        $since = $this->option('since');

        if (! is_string($since) || $since === '') {
            return $files;
        }

        $process = new Process(['git', 'diff', '--name-only', $since, '--', '.'], $migrationsPath);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->components->warn("--since={$since} failed to run `git diff`; linting all files.");

            return $files;
        }

        $changed = array_filter(explode("\n", trim($process->getOutput())));
        $changedBasenames = array_map(fn (string $p): string => basename($p), $changed);

        return array_values(array_filter(
            $files,
            fn (string $file): bool => in_array(basename($file), $changedBasenames, true),
        ));
    }

    private function buildContext(string $driver): LintContext
    {
        $baselinePath = config('safe-schema.baseline_path');
        $baseline = [];

        if (is_string($baselinePath) && is_file($baselinePath)) {
            $decoded = json_decode((string) file_get_contents($baselinePath), true);

            if (is_array($decoded)) {
                $baseline = $decoded;
            }
        }

        $connection = $this->tryConnect();

        return new LintContext(
            driver: $driver,
            serverVersion: $this->resolveServerVersion($connection),
            tableSizes: TableSizeResolver::fromBaselineFile($baseline, $connection),
            config: (array) config('safe-schema'),
        );
    }

    private function tryConnect(): ?Connection
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();

            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveServerVersion(?Connection $connection): ServerVersion
    {
        $configured = config('safe-schema.server_version');

        if (is_string($configured) && $configured !== '') {
            return ServerVersion::from($configured);
        }

        if ($connection === null) {
            return ServerVersion::unknown();
        }

        try {
            $raw = $connection->selectOne('SELECT version() AS version')?->version;

            if (! is_string($raw)) {
                return ServerVersion::unknown();
            }

            return match ($connection->getDriverName()) {
                'pgsql' => ServerVersion::fromPostgresVersionString($raw),
                'mysql' => ServerVersion::fromMysqlVersionString($raw),
                default => ServerVersion::unknown(),
            };
        } catch (\Throwable) {
            return ServerVersion::unknown();
        }
    }

    private function render(LintResult $result): void
    {
        $format = (string) $this->option('format');

        match ($format) {
            'json' => (new JsonReporter($this->output))->report($result),
            'github' => (new GithubReporter($this->output))->report($result),
            default => (new ConsoleReporter($this->output))->report($result),
        };
    }
}
