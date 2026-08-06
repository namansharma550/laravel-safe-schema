<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Rules\Generic;

use RGInfotech\SafeSchema\Analysis\Operation;
use RGInfotech\SafeSchema\Context\LintContext;
use RGInfotech\SafeSchema\Results\Violation;
use RGInfotech\SafeSchema\Rules\Rule;

final class BackfillInMigrationRule implements Rule
{
    public function id(): string
    {
        return 'backfill-in-migration';
    }

    public function drivers(): array
    {
        return ['pgsql', 'mysql'];
    }

    public function appliesTo(Operation $operation): bool
    {
        return $operation->type === 'backfill_loop';
    }

    public function check(Operation $operation, LintContext $context): Violation
    {
        return new Violation(
            ruleId: $this->id(),
            line: $operation->line,
            table: '(backfill loop)',
            message: "This loop writes rows one at a time inside the migration. On a driver\n"
                ."with transactional DDL, the whole migration - including this loop - runs\n"
                .'inside a single transaction, so it holds locks and an open transaction for '
                .'as long as the backfill takes. On a large table that can be minutes or '
                .'hours, during which nothing else can safely deploy.',
            safeAlternative: $this->safeAlternative($operation),
        );
    }

    public function safeAlternative(Operation $operation): string
    {
        return "Batch the backfill outside the migration's implicit transaction:\n\n"
            ."DB::table('table_name')->whereNull('column')\n"
            ."    ->orderBy('id')\n"
            ."    ->chunkById(1000, function (\$rows) {\n"
            ."        // update each chunk in its own short transaction\n"
            ."    });\n\n"
            .'Or move the backfill to a queued job that runs after the migration, outside '
            .'the deploy-blocking path entirely.';
    }
}
