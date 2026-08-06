<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Rules\Concerns;

use RGInfotech\SafeSchema\Analysis\Operation;
use RGInfotech\SafeSchema\Context\LintContext;

trait ChecksTableSize
{
    /**
     * Returns null when the table is below the configured threshold and
     * should stay silent. Returns [rows, unknown] otherwise.
     *
     * @return array{0: ?int, 1: bool}|null
     */
    private function sizeGate(Operation $operation, LintContext $context): ?array
    {
        $rows = $context->tableSizes->estimateRows($operation->table);

        if ($rows === null) {
            return [null, true];
        }

        if ($rows < $context->minRows()) {
            return null;
        }

        return [$rows, false];
    }
}
