<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Rules\Postgres;

use RGInfotech\SafeMigrations\Analysis\Operation;
use RGInfotech\SafeMigrations\Context\LintContext;
use RGInfotech\SafeMigrations\Results\Violation;
use RGInfotech\SafeMigrations\Rules\Rule;

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
