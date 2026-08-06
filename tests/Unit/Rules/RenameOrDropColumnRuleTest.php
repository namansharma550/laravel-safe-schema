<?php

declare(strict_types=1);

use RGInfotech\SafeMigrations\Rules\Generic\RenameOrDropColumnRule;

it('flags dropColumn on an existing table', function () {
    $operations = lintOperationsFor('rename-or-drop-column', 'dangerous/drop_column.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new RenameOrDropColumnRule, $operations, $context);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->ruleId)->toBe('rename-or-drop-column')
        ->and($violations[0]->table)->toBe('orders');
});

it('flags renameColumn on an existing table', function () {
    $operations = lintOperationsFor('rename-or-drop-column', 'dangerous/rename_column.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new RenameOrDropColumnRule, $operations, $context);

    expect($violations)->toHaveCount(1);
});

it('stays silent when dropping a column on a table created in the same file', function () {
    $operations = lintOperationsFor('rename-or-drop-column', 'safe/drop_column_on_new_table.php');

    foreach ($operations as $operation) {
        expect($operation->tableCreatedInSameFile)->toBeTrue();
    }
});

it('stays silent on adding a column', function () {
    $operations = lintOperationsFor('rename-or-drop-column', 'safe/add_column.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new RenameOrDropColumnRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});
