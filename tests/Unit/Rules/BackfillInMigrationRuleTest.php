<?php

declare(strict_types=1);

use RGInfotech\SafeSchema\Rules\Generic\BackfillInMigrationRule;

it('flags an update call inside a foreach loop', function () {
    $operations = lintOperationsFor('backfill-in-migration', 'dangerous/update_loop.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new BackfillInMigrationRule, $operations, $context);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->ruleId)->toBe('backfill-in-migration');
});

it('stays silent on a single update call with no loop', function () {
    $operations = lintOperationsFor('backfill-in-migration', 'safe/chunked_update_no_loop_write.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new BackfillInMigrationRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});

it('stays silent on a loop that does not write', function () {
    $operations = lintOperationsFor('backfill-in-migration', 'safe/loop_without_write.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new BackfillInMigrationRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});
