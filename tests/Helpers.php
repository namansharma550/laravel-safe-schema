<?php

declare(strict_types=1);

use RGInfotech\SafeMigrations\Analysis\MigrationParser;
use RGInfotech\SafeMigrations\Analysis\Operation;
use RGInfotech\SafeMigrations\Context\LintContext;
use RGInfotech\SafeMigrations\Context\ServerVersion;
use RGInfotech\SafeMigrations\Context\TableSizeResolver;
use RGInfotech\SafeMigrations\Results\Violation;
use RGInfotech\SafeMigrations\Rules\Rule;

/**
 * @return list<Operation>
 */
function lintOperationsFor(string $ruleFolder, string $fixture): array
{
    $file = (new MigrationParser)->parse(
        __DIR__.'/Fixtures/'.$ruleFolder.'/'.$fixture,
        fn (string $w) => throw new RuntimeException("Unexpected parse warning: {$w}"),
    );

    expect($file)->not->toBeNull();

    return $file->operations;
}

/**
 * @param  array<string, int>  $sizes
 */
function contextWithSizes(array $sizes, ?string $serverVersion = '16.2'): LintContext
{
    return new LintContext(
        driver: 'pgsql',
        serverVersion: $serverVersion !== null ? ServerVersion::from($serverVersion) : ServerVersion::unknown(),
        tableSizes: TableSizeResolver::fromBaselineFile(['tables' => $sizes]),
        config: ['min_rows' => 10000],
    );
}

/**
 * @param  list<Operation>  $operations
 * @return list<Violation>
 */
function violationsFor(Rule $rule, array $operations, LintContext $context): array
{
    $violations = [];

    foreach ($operations as $operation) {
        if (! $rule->appliesTo($operation)) {
            continue;
        }

        $violation = $rule->check($operation, $context);

        if ($violation !== null) {
            $violations[] = $violation;
        }
    }

    return $violations;
}
