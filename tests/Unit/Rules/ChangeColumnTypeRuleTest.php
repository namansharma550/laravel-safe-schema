<?php

declare(strict_types=1);

use RGInfotech\SafeMigrations\Rules\Postgres\ChangeColumnTypeRule;

it('flags a column type change on a large existing table', function () {
    $operations = lintOperationsFor('change-column-type', 'dangerous/change_amount_type.php');
    $context = contextWithSizes(['orders' => 8_400_000]);
    $violations = violationsFor(new ChangeColumnTypeRule, $operations, $context);

    expect($violations)->toHaveCount(1);

    expect($violations[0]->ruleId)->toBe('change-column-type')
        ->and($violations[0]->table)->toBe('orders')
        ->and($violations[0]->estimatedRows)->toBe(8_400_000);
});

it('stays silent on nullable(false)->change(), which is owned by set-not-null', function () {
    $operations = lintOperationsFor('change-column-type', 'safe/not_null_owned_by_other_rule.php');
    $context = contextWithSizes(['orders' => 8_400_000]);
    $violations = violationsFor(new ChangeColumnTypeRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});

it('stays silent when the table is below the size threshold', function () {
    $operations = lintOperationsFor('change-column-type', 'safe/change_type_below_threshold.php');
    $context = contextWithSizes(['small_lookup_table' => 42]);
    $violations = violationsFor(new ChangeColumnTypeRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});
