<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Rules\Postgres;

use RGInfotech\SafeSchema\Analysis\Operation;
use RGInfotech\SafeSchema\Context\LintContext;
use RGInfotech\SafeSchema\Results\Violation;
use RGInfotech\SafeSchema\Rules\Rule;

final class ConcurrentInTransactionRule implements Rule
{
    public function id(): string
    {
        return 'concurrent-in-transaction';
    }

    public function drivers(): array
    {
        return ['pgsql'];
    }

    public function appliesTo(Operation $operation): bool
    {
        if ($operation->type !== 'raw_statement' || ! $operation->withinTransaction) {
            return false;
        }

        $sql = (string) ($operation->options['sql'] ?? '');

        return stripos($sql, 'concurrently') !== false;
    }

    public function check(Operation $operation, LintContext $context): Violation
    {
        return new Violation(
            ruleId: $this->id(),
            line: $operation->line,
            table: $operation->table !== '' ? $operation->table : '(raw SQL)',
            message: "CREATE/DROP INDEX CONCURRENTLY cannot run inside a transaction block.\n"
                .'Postgres will hard-error at migration time because this migration class '
                .'does not set $withinTransaction = false.',
            safeAlternative: $this->safeAlternative($operation),
        );
    }

    public function safeAlternative(Operation $operation): string
    {
        return 'public $withinTransaction = false;';
    }
}
