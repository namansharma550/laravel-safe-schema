<?php

declare(strict_types=1);

use RGInfotech\SafeMigrations\Rules\Postgres\AddForeignKeyRule;

it('flags a foreign key added to a large existing table', function () {
    $operations = lintOperationsFor('add-foreign-key', 'dangerous/add_foreign_key_to_orders.php');
    $context = contextWithSizes(['orders' => 8_400_000]);
    $violations = violationsFor(new AddForeignKeyRule, $operations, $context);

    expect($violations)->toHaveCount(1);

    expect($violations[0]->ruleId)->toBe('add-foreign-key')
        ->and($violations[0]->table)->toBe('orders')
        ->and($violations[0]->estimatedRows)->toBe(8_400_000);
});

it('stays silent when the foreign key is on a table created in the same file', function () {
    $operations = lintOperationsFor('add-foreign-key', 'safe/foreign_key_on_new_table.php');

    foreach ($operations as $operation) {
        expect($operation->tableCreatedInSameFile)->toBeTrue();
    }
});

it('stays silent when the table is below the size threshold', function () {
    $operations = lintOperationsFor('add-foreign-key', 'safe/foreign_key_below_threshold.php');
    $context = contextWithSizes(['small_lookup_table' => 42]);
    $violations = violationsFor(new AddForeignKeyRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});
