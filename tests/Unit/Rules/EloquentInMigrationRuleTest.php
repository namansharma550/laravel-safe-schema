<?php

declare(strict_types=1);

use RGInfotech\SafeSchema\Rules\Generic\EloquentInMigrationRule;

it('flags a static call on an App\Models class', function () {
    $operations = lintOperationsFor('eloquent-in-migration', 'dangerous/uses_model_static_call.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new EloquentInMigrationRule, $operations, $context);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->ruleId)->toBe('eloquent-in-migration');
});

it('flags instantiating an App\Models class', function () {
    $operations = lintOperationsFor('eloquent-in-migration', 'dangerous/uses_model_new.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new EloquentInMigrationRule, $operations, $context);

    expect($violations)->toHaveCount(1);
});

it('stays silent when using the query builder instead of a model', function () {
    $operations = lintOperationsFor('eloquent-in-migration', 'safe/uses_query_builder.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new EloquentInMigrationRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});

it('stays silent on unrelated Schema/Blueprint usage', function () {
    $operations = lintOperationsFor('eloquent-in-migration', 'safe/uses_unrelated_class.php');
    $context = contextWithSizes([]);
    $violations = violationsFor(new EloquentInMigrationRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});
