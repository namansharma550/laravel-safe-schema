<?php

declare(strict_types=1);

use RGInfotech\SafeSchema\Rules\Postgres\ConcurrentInTransactionRule;

it('flags CREATE INDEX CONCURRENTLY when withinTransaction is not disabled', function () {
    $operations = lintOperationsFor('concurrent-in-transaction', 'dangerous/concurrently_without_flag.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new ConcurrentInTransactionRule, $operations, $context);

    expect($violations)->toHaveCount(1);

    expect($violations[0]->ruleId)->toBe('concurrent-in-transaction');
});

it('stays silent when withinTransaction is explicitly disabled', function () {
    $operations = lintOperationsFor('concurrent-in-transaction', 'safe/concurrently_with_flag_disabled.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new ConcurrentInTransactionRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});

it('stays silent on a raw statement that does not use CONCURRENTLY', function () {
    $operations = lintOperationsFor('concurrent-in-transaction', 'safe/non_concurrent_raw_statement.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new ConcurrentInTransactionRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});
