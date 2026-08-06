<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Rules\MySQL;

use RGInfotech\SafeMigrations\Analysis\Operation;
use RGInfotech\SafeMigrations\Context\LintContext;
use RGInfotech\SafeMigrations\Results\Violation;
use RGInfotech\SafeMigrations\Rules\Rule;

final class MysqlMultiDdlRule implements Rule
{
    public function id(): string
    {
        return 'mysql-multi-ddl';
    }

    public function drivers(): array
    {
        return ['mysql'];
    }

    public function appliesTo(Operation $operation): bool
    {
        return $operation->ddlPosition !== null && $operation->ddlPosition >= 2;
    }

    public function check(Operation $operation, LintContext $context): Violation
    {
        return new Violation(
            ruleId: $this->id(),
            line: $operation->line,
            table: $operation->table !== '' ? $operation->table : '(raw SQL)',
            message: "This is DDL statement #{$operation->ddlPosition} in this migration. MySQL\n"
                ."has no transactional DDL: each statement commits immediately and\n"
                .'independently. If a later statement in this file fails, the earlier ones '
                .'have already been applied - there is no automatic rollback, and the '
                .'schema is left in a partially-migrated state.',
            safeAlternative: $this->safeAlternative($operation),
        );
    }

    public function safeAlternative(Operation $operation): string
    {
        return 'Split this into one DDL statement per migration file. Each migration then '
            .'either fully applies or is the single statement that failed - there is no '
            .'partial state to reason about or manually roll back.';
    }
}
