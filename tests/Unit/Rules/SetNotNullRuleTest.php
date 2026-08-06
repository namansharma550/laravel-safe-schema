<?php

declare(strict_types=1);

use RGInfotech\SafeMigrations\Rules\Postgres\SetNotNullRule;

it('flags nullable(false)->change() on a large existing table', function () {
    $operations = lintOperationsFor('set-not-null', 'dangerous/set_status_not_null.php');
    $context = contextWithSizes(['orders' => 8_400_000]);
    $violations = violationsFor(new SetNotNullRule, $operations, $context);

    expect($violations)->toHaveCount(1);

    expect($violations[0]->ruleId)->toBe('set-not-null')
        ->and($violations[0]->table)->toBe('orders')
        ->and($violations[0]->estimatedRows)->toBe(8_400_000);
});

it('suggests the validated-CHECK trick on PG 12+', function () {
    $operations = lintOperationsFor('set-not-null', 'dangerous/set_status_not_null.php');
    $context = contextWithSizes(['orders' => 8_400_000], serverVersion: '16.2');
    $violations = violationsFor(new SetNotNullRule, $operations, $context);

    expect($violations[0]->safeAlternative)->toContain('VALIDATE CONSTRAINT');
});

it('suggests expand/contract instead of the validated-CHECK trick before PG 12', function () {
    $operations = lintOperationsFor('set-not-null', 'dangerous/set_status_not_null.php');
    $context = contextWithSizes(['orders' => 8_400_000], serverVersion: '11.9');
    $violations = violationsFor(new SetNotNullRule, $operations, $context);

    expect($violations[0]->safeAlternative)->not->toContain('VALIDATE CONSTRAINT')
        ->and($violations[0]->safeAlternative)->toContain('renameColumn');
});

it('stays silent on a plain type change without nullable(false)', function () {
    $operations = lintOperationsFor('set-not-null', 'safe/type_change_is_not_not_null.php');
    $context = contextWithSizes(['orders' => 8_400_000]);
    $violations = violationsFor(new SetNotNullRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});

it('stays silent when the table is below the size threshold', function () {
    $operations = lintOperationsFor('set-not-null', 'safe/not_null_below_threshold.php');
    $context = contextWithSizes(['small_lookup_table' => 42]);
    $violations = violationsFor(new SetNotNullRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});
