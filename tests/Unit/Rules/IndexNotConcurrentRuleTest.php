<?php

declare(strict_types=1);

use RGInfotech\SafeSchema\Rules\Postgres\IndexNotConcurrentRule;

it('flags a non-concurrent index on a large existing table', function () {
    $operations = lintOperationsFor('index-not-concurrent', 'dangerous/add_index_to_orders.php');
    $context = contextWithSizes(['orders' => 8_400_000]);
    $violations = violationsFor(new IndexNotConcurrentRule, $operations, $context);

    expect($violations)->toHaveCount(1);

    expect($violations[0]->ruleId)->toBe('index-not-concurrent')
        ->and($violations[0]->table)->toBe('orders')
        ->and($violations[0]->estimatedRows)->toBe(8_400_000)
        ->and($violations[0]->sizeUnknown)->toBeFalse();
});

it('stays silent when the index is on a table created in the same file', function () {
    $operations = lintOperationsFor('index-not-concurrent', 'safe/index_on_new_table.php');

    foreach ($operations as $operation) {
        expect($operation->tableCreatedInSameFile)->toBeTrue();
    }
});

it('stays silent when the table is below the size threshold', function () {
    $operations = lintOperationsFor('index-not-concurrent', 'safe/index_below_threshold.php');
    $context = contextWithSizes(['small_lookup_table' => 42]);
    $violations = violationsFor(new IndexNotConcurrentRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});
