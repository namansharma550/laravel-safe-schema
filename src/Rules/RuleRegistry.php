<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Rules;

final class RuleRegistry
{
    /**
     * @var list<Rule>
     */
    private array $rules = [];

    public function register(Rule $rule): void
    {
        $this->rules[] = $rule;
    }

    /**
     * @return list<Rule>
     */
    public function forDriver(string $driver): array
    {
        return array_values(array_filter(
            $this->rules,
            fn (Rule $rule): bool => in_array($driver, $rule->drivers(), true),
        ));
    }

    /**
     * @return list<Rule>
     */
    public function all(): array
    {
        return $this->rules;
    }
}
