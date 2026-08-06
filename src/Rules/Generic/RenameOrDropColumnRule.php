<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Rules\Generic;

use RGInfotech\SafeSchema\Analysis\Operation;
use RGInfotech\SafeSchema\Context\LintContext;
use RGInfotech\SafeSchema\Results\Violation;
use RGInfotech\SafeSchema\Rules\Rule;

final class RenameOrDropColumnRule implements Rule
{
    public function id(): string
    {
        return 'rename-or-drop-column';
    }

    public function drivers(): array
    {
        return ['pgsql', 'mysql'];
    }

    public function appliesTo(Operation $operation): bool
    {
        return in_array($operation->type, ['rename_column', 'drop_column'], true);
    }

    public function check(Operation $operation, LintContext $context): Violation
    {
        $action = $operation->type === 'rename_column' ? 'Renaming' : 'Dropping';

        return new Violation(
            ruleId: $this->id(),
            line: $operation->line,
            table: $operation->table,
            message: "{$action} a column is not a locking problem - it's a mid-deploy breakage\n"
                ."problem. The old application code still running on other servers keeps\n"
                ."querying `{$operation->table}` for the column that just disappeared, and\n"
                .'every request it handles until the deploy finishes will error.',
            safeAlternative: $this->safeAlternative($operation),
        );
    }

    public function safeAlternative(Operation $operation): string
    {
        $column = (string) ($operation->options['column'] ?? 'column');
        $table = $operation->table;

        return "Expand/contract across separate deploys instead of a single migration:\n\n"
            ."// Deploy 1: add the new column (or stop reading the old one), ship\n"
            ."// application code that writes to both / reads from the new column\n\n"
            ."// Deploy 2, once no running code references the old column:\n"
            ."\$table->dropColumn('{$column}'); // or renameColumn on {$table}";
    }
}
