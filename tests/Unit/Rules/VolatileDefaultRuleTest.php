<?php

declare(strict_types=1);

use RGInfotech\SafeMigrations\Rules\Postgres\VolatileDefaultRule;

it('flags a non-constant default on a large existing table', function () {
    $operations = lintOperationsFor('volatile-default', 'dangerous/add_column_with_now_default.php');
    $context = contextWithSizes(['orders' => 8_400_000]);
    $violations = violationsFor(new VolatileDefaultRule, $operations, $context);

    expect($violations)->toHaveCount(1);

    expect($violations[0]->ruleId)->toBe('volatile-default')
        ->and($violations[0]->table)->toBe('orders')
        ->and($violations[0]->estimatedRows)->toBe(8_400_000);
});

it('stays silent on a constant default', function () {
    $operations = lintOperationsFor('volatile-default', 'safe/add_column_with_constant_default.php');
    $context = contextWithSizes(['orders' => 8_400_000]);
    $violations = violationsFor(new VolatileDefaultRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});

it('stays silent when the table is below the size threshold', function () {
    $operations = lintOperationsFor('volatile-default', 'safe/volatile_default_below_threshold.php');
    $context = contextWithSizes(['small_lookup_table' => 42]);
    $violations = violationsFor(new VolatileDefaultRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});

it('flags even a constant default on Postgres versions before 11', function () {
    $operations = lintOperationsFor('volatile-default', 'safe/add_column_with_constant_default.php');
    $context = contextWithSizes(['orders' => 8_400_000], serverVersion: '10.4');
    $violations = violationsFor(new VolatileDefaultRule, $operations, $context);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->ruleId)->toBe('volatile-default');
});

it('flags a constant default when the server version is unknown, assuming the conservative case', function () {
    $operations = lintOperationsFor('volatile-default', 'safe/add_column_with_constant_default.php');
    $context = contextWithSizes(['orders' => 8_400_000], serverVersion: null);
    $violations = violationsFor(new VolatileDefaultRule, $operations, $context);

    expect($violations)->toHaveCount(1);
});
