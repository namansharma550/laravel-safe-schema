<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Context;

final class LintContext
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public readonly string $driver,
        public readonly ServerVersion $serverVersion,
        public readonly TableSizeResolver $tableSizes,
        public readonly array $config,
    ) {}

    public function minRows(): int
    {
        return (int) ($this->config['min_rows'] ?? 10000);
    }

    public function isRuleEnabled(string $ruleId): bool
    {
        $disabled = $this->config['disabled_rules'] ?? [];

        return ! in_array($ruleId, $disabled, true);
    }
}
