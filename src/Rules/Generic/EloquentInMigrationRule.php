<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Rules\Generic;

use RGInfotech\SafeMigrations\Analysis\Operation;
use RGInfotech\SafeMigrations\Context\LintContext;
use RGInfotech\SafeMigrations\Results\Violation;
use RGInfotech\SafeMigrations\Rules\Rule;

final class EloquentInMigrationRule implements Rule
{
    public function id(): string
    {
        return 'eloquent-in-migration';
    }

    public function drivers(): array
    {
        return ['pgsql', 'mysql'];
    }

    public function appliesTo(Operation $operation): bool
    {
        return $operation->type === 'model_reference';
    }

    public function check(Operation $operation, LintContext $context): Violation
    {
        return new Violation(
            ruleId: $this->id(),
            line: $operation->line,
            table: '(model reference)',
            message: "This migration references an App\\Models\\ class. Models drift from the\n"
                ."schema over time - a scope, cast, or accessor added months from now will\n"
                .'silently change what this migration does when it finally runs against a '
                .'fresh database, or break outright if a column the model expects has since '
                .'been renamed or removed.',
            safeAlternative: $this->safeAlternative($operation),
        );
    }

    public function safeAlternative(Operation $operation): string
    {
        return "Use the query builder instead of the model:\n\n"
            ."DB::table('table_name')->where(...)->update([...]);\n\n"
            .'This runs against the schema as it exists right now, independent of how the '
            .'model evolves later.';
    }
}
