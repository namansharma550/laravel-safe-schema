<?php

declare(strict_types=1);

use RGInfotech\SafeMigrations\Rules\MySQL\MysqlMultiDdlRule;

it('flags the second of two Schema::table() calls in one migration', function () {
    $operations = lintOperationsFor('mysql-multi-ddl', 'dangerous/two_schema_table_calls.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new MysqlMultiDdlRule, $operations, $context);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->ruleId)->toBe('mysql-multi-ddl')
        ->and($violations[0]->table)->toBe('order_items');
});

it('stays silent on a single Schema::table() call with multiple columns', function () {
    $operations = lintOperationsFor('mysql-multi-ddl', 'safe/single_schema_table_call.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new MysqlMultiDdlRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});

it('stays silent on a single Schema::create() call', function () {
    $operations = lintOperationsFor('mysql-multi-ddl', 'safe/single_create_call.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new MysqlMultiDdlRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});
