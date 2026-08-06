<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Rules\Postgres;

use RGInfotech\SafeSchema\Analysis\Operation;
use RGInfotech\SafeSchema\Context\LintContext;
use RGInfotech\SafeSchema\Results\Violation;
use RGInfotech\SafeSchema\Rules\Concerns\ChecksTableSize;
use RGInfotech\SafeSchema\Rules\Rule;

final class AddForeignKeyRule implements Rule
{
    use ChecksTableSize;

    public function id(): string
    {
        return 'add-foreign-key';
    }

    public function drivers(): array
    {
        return ['pgsql'];
    }

    public function appliesTo(Operation $operation): bool
    {
        return $operation->type === 'add_foreign_key';
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
            message: "Adding a foreign key takes an ACCESS EXCLUSIVE lock on both tables while\n"
                ."Postgres validates every existing row in `{$operation->table}` against the\n"
                .'referenced table. Writes to both tables block until validation finishes.',
            safeAlternative: $this->safeAlternative($operation),
            estimatedRows: $rows,
            sizeUnknown: $unknown,
        );
    }

    public function safeAlternative(Operation $operation): string
    {
        $column = (string) ($operation->options['column'] ?? 'column');

        return "// Migration 1: add the constraint without validating existing rows\n"
            ."DB::statement('ALTER TABLE {$operation->table} ADD CONSTRAINT {$operation->table}_{$column}_fk "
            ."FOREIGN KEY ({$column}) REFERENCES ... NOT VALID');\n\n"
            ."// Migration 2 (separate deploy): validate without blocking writers\n"
            ."DB::statement('ALTER TABLE {$operation->table} VALIDATE CONSTRAINT {$operation->table}_{$column}_fk');";
    }
}
