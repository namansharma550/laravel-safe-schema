<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Rules\Postgres;

use RGInfotech\SafeSchema\Analysis\Operation;
use RGInfotech\SafeSchema\Context\LintContext;
use RGInfotech\SafeSchema\Results\Violation;
use RGInfotech\SafeSchema\Rules\Concerns\ChecksTableSize;
use RGInfotech\SafeSchema\Rules\Rule;

final class VolatileDefaultRule implements Rule
{
    use ChecksTableSize;

    public function id(): string
    {
        return 'volatile-default';
    }

    public function drivers(): array
    {
        return ['pgsql'];
    }

    public function appliesTo(Operation $operation): bool
    {
        return $operation->type === 'add_column' && in_array('default', $operation->methodChain, true);
    }

    public function check(Operation $operation, LintContext $context): ?Violation
    {
        $defaultArgs = $operation->options['args_by_method']['default'] ?? [];
        $isVolatile = ($defaultArgs[0] ?? null) === '__non_constant__';

        // Before PG 11, ADD COLUMN with ANY default (even constant) rewrote
        // the whole table. From PG 11 on, only a volatile default still does.
        // An unknown version can't be assumed safe, so it's treated like <11.
        if (! $isVolatile && $context->serverVersion->isAtLeast(11)) {
            return null;
        }

        $gate = $this->sizeGate($operation, $context);

        if ($gate === null) {
            return null;
        }

        [$rows, $unknown] = $gate;

        return new Violation(
            ruleId: $this->id(),
            line: $operation->line,
            table: $operation->table,
            message: $isVolatile
                ? 'A non-constant default (a function call such as now() or a '
                    ."subquery) forces a full table rewrite of `{$operation->table}` even on\n"
                    .'Postgres 11+, where constant defaults are normally instant.'
                : "Adding a column with a default value forces a full table rewrite of\n"
                    ."`{$operation->table}` on Postgres versions before 11".
                    ($context->serverVersion->isKnown() ? '' : ' (server version unknown, assuming the conservative case)').
                    '.',
            safeAlternative: $this->safeAlternative($operation),
            estimatedRows: $rows,
            sizeUnknown: $unknown,
        );
    }

    public function safeAlternative(Operation $operation): string
    {
        $column = (string) ($operation->options['column'] ?? 'column');
        $table = $operation->table;

        return "// 1. Add the column nullable, no default\n"
            ."\$table->string('{$column}')->nullable();\n\n"
            ."// 2. Backfill in batches\n"
            ."DB::table('{$table}')->whereNull('{$column}')->update(['{$column}' => ...]);\n\n"
            ."// 3. Set the default for future inserts only, in a later migration\n"
            ."DB::statement('ALTER TABLE {$table} ALTER COLUMN {$column} SET DEFAULT ...');";
    }
}
