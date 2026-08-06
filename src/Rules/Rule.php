<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Rules;

use RGInfotech\SafeMigrations\Analysis\Operation;
use RGInfotech\SafeMigrations\Context\LintContext;
use RGInfotech\SafeMigrations\Results\Violation;

interface Rule
{
    public function id(): string;

    /**
     * @return list<string>
     */
    public function drivers(): array;

    public function appliesTo(Operation $operation): bool;

    public function check(Operation $operation, LintContext $context): ?Violation;

    public function safeAlternative(Operation $operation): string;
}
