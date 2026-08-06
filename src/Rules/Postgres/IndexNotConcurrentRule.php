<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Rules\Postgres;

use RGInfotech\SafeSchema\Analysis\Operation;
use RGInfotech\SafeSchema\Context\LintContext;
use RGInfotech\SafeSchema\Results\Violation;
use RGInfotech\SafeSchema\Rules\Concerns\ChecksTableSize;
use RGInfotech\SafeSchema\Rules\Rule;

final class IndexNotConcurrentRule implements Rule
{
    use ChecksTableSize;

    public function id(): string
    {
        return 'index-not-concurrent';
    }

    public function drivers(): array
    {
        return ['pgsql'];
    }

    public function appliesTo(Operation $operation): bool
    {
        return in_array($operation->type, ['add_index', 'add_unique'], true);
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
            message: "A non-concurrent CREATE INDEX takes a lock that blocks all writes to\n"
                ."`{$operation->table}` until the index finishes building. Other queries then queue\n"
                .'behind it.',
            safeAlternative: $this->safeAlternative($operation),
            estimatedRows: $rows,
            sizeUnknown: $unknown,
        );
    }

    public function safeAlternative(Operation $operation): string
    {
        $column = (string) ($operation->options['column'] ?? 'column');

        return "public \$withinTransaction = false;\n\n"
            ."public function up(): void\n"
            ."{\n"
            ."    DB::statement('CREATE INDEX CONCURRENTLY idx_{$operation->table}_{$column} ON {$operation->table} ({$column})');\n"
            .'}';
    }
}
