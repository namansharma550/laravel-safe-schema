<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Rules\Postgres;

use RGInfotech\SafeSchema\Analysis\Operation;
use RGInfotech\SafeSchema\Context\LintContext;
use RGInfotech\SafeSchema\Results\Violation;
use RGInfotech\SafeSchema\Rules\Concerns\ChecksTableSize;
use RGInfotech\SafeSchema\Rules\Rule;

final class SetNotNullRule implements Rule
{
    use ChecksTableSize;

    public function id(): string
    {
        return 'set-not-null';
    }

    public function drivers(): array
    {
        return ['pgsql'];
    }

    public function appliesTo(Operation $operation): bool
    {
        if ($operation->type !== 'alter_column' || ! in_array('nullable', $operation->methodChain, true)) {
            return false;
        }

        $nullableArgs = $operation->options['args_by_method']['nullable'] ?? [];

        return ($nullableArgs[0] ?? true) === false;
    }

    public function check(Operation $operation, LintContext $context): ?Violation
    {
        $gate = $this->sizeGate($operation, $context);

        if ($gate === null) {
            return null;
        }

        [$rows, $unknown] = $gate;

        $alternative = $context->serverVersion->isAtLeast(12)
            ? $this->validatedCheckAlternative($operation)
            : $this->prePg12Alternative($operation);

        return new Violation(
            ruleId: $this->id(),
            line: $operation->line,
            table: $operation->table,
            message: "SET NOT NULL forces Postgres to scan the entire table under an\n"
                ."ACCESS EXCLUSIVE lock to verify no existing row is null, blocking all\n"
                ."reads and writes to `{$operation->table}` for the duration of the scan.",
            safeAlternative: $alternative,
            estimatedRows: $rows,
            sizeUnknown: $unknown,
        );
    }

    /**
     * Version-agnostic default for callers invoking this without a
     * LintContext. Documents the PG 12+ path, which is the common case.
     */
    public function safeAlternative(Operation $operation): string
    {
        return $this->validatedCheckAlternative($operation);
    }

    /**
     * PG 12+: a validated CHECK constraint lets SET NOT NULL skip its own
     * rescan, since the planner can prove no row is null from the
     * already-validated constraint.
     */
    private function validatedCheckAlternative(Operation $operation): string
    {
        $column = (string) ($operation->options['column'] ?? 'column');
        $table = $operation->table;

        return "// PG 12+: a validated CHECK constraint lets SET NOT NULL skip its own rescan\n"
            ."DB::statement(\"ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_not_null \"\n"
            ."    .\"CHECK ({$column} IS NOT NULL) NOT VALID\");\n"
            ."DB::statement('ALTER TABLE {$table} VALIDATE CONSTRAINT {$table}_{$column}_not_null');\n"
            ."DB::statement('ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL');\n"
            ."DB::statement('ALTER TABLE {$table} DROP CONSTRAINT {$table}_{$column}_not_null');";
    }

    /**
     * Before PG 12, the validated-CHECK trick above does not skip the
     * SET NOT NULL rescan, so the only safe path is expand/contract: add a
     * NOT NULL column with a default, backfill, then drop the old one.
     */
    private function prePg12Alternative(Operation $operation): string
    {
        $column = (string) ($operation->options['column'] ?? 'column');
        $table = $operation->table;

        return "// Before PG 12, SET NOT NULL always rescans the table even with a\n"
            ."// validated CHECK constraint in place, so expand/contract instead:\n"
            ."// 1. Add a new NOT NULL column with a default\n"
            ."\$table->string('{$column}_new')->nullable(false)->default('...');\n\n"
            ."// 2. Backfill in batches, then in a later migration:\n"
            ."DB::table('{$table}')->update(['{$column}_new' => DB::raw('{$column}')]);\n\n"
            ."// 3. Swap application reads/writes to the new column, then drop the old one\n"
            ."\$table->renameColumn('{$column}_new', '{$column}');";
    }
}
