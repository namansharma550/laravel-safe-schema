<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Rules;

use RGInfotech\SafeSchema\Analysis\Operation;
use RGInfotech\SafeSchema\Context\LintContext;
use RGInfotech\SafeSchema\Results\Violation;

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
