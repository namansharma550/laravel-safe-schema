<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Rules\Postgres;

use RGInfotech\SafeMigrations\Analysis\Operation;
use RGInfotech\SafeMigrations\Context\LintContext;
use RGInfotech\SafeMigrations\Results\Violation;
use RGInfotech\SafeMigrations\Rules\Concerns\ChecksTableSize;
use RGInfotech\SafeMigrations\Rules\Rule;

final class ChangeColumnTypeRule implements Rule
{
    use ChecksTableSize;

    public function id(): string
    {
        return 'change-column-type';
    }

    public function drivers(): array
    {
        return ['pgsql'];
    }

    public function appliesTo(Operation $operation): bool
    {
        if ($operation->type !== 'alter_column') {
            return false;
        }

        // set-not-null owns the ->nullable(false)->change() case exclusively.
        $nullableArgs = $operation->options['args_by_method']['nullable'] ?? null;

        if ($nullableArgs !== null && ($nullableArgs[0] ?? true) === false) {
            return false;
        }

        // The type-changing method is whichever column-type method appears
        // before ->change() in the chain (string, integer, decimal, etc).
        // Chains that only touch nullable()/default()/comment() before
        // change() aren't a type change and are out of scope for v1.
        $typeMethods = array_diff($operation->methodChain, ['change', 'nullable', 'default', 'comment', 'after', 'first']);

        return $typeMethods !== [];
    }

    public function check(Operation $operation, LintContext $context): ?Violation
    {
        $gate = $this->sizeGate($operation, $context);

        if ($gate === null) {
            return null;
        }

        [$rows, $unknown] = $gate;

        return new Violation(
            ruleId: $this->id(),
            line: $operation->line,
            table: $operation->table,
            message: "Changing a column's type usually forces Postgres to rewrite every row\n"
                ."of `{$operation->table}` under an ACCESS EXCLUSIVE lock, blocking all reads\n"
                .'and writes until the rewrite completes.',
            safeAlternative: $this->safeAlternative($operation),
            estimatedRows: $rows,
            sizeUnknown: $unknown,
        );
    }

    public function safeAlternative(Operation $operation): string
    {
        $column = (string) ($operation->options['column'] ?? 'column');
        $table = $operation->table;

        return "// Expand/contract across separate deploys instead of an in-place change:\n"
            ."// 1. Add a new nullable column with the target type\n"
            ."\$table->string('{$column}_new')->nullable();\n\n"
            ."// 2. Backfill in batches (application code or a queued job), then\n"
            ."//    swap reads/writes to the new column in application code\n\n"
            ."// 3. In a later migration: rename or drop the old column, drop \"_new\" suffix\n"
            ."\$table->renameColumn('{$column}_new', '{$column}');";
    }
}
