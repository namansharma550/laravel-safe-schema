<?php

declare(strict_types=1);

use RGInfotech\SafeMigrations\Analysis\MigrationParser;

it('returns null and warns instead of crashing on an unparseable file', function () {
    $warnings = [];

    $file = (new MigrationParser)->parse(
        __DIR__.'/../../Fixtures/edge-cases/unparseable.php',
        function (string $w) use (&$warnings) {
            $warnings[] = $w;
        },
    );

    expect($file)->toBeNull()
        ->and($warnings)->not->toBeEmpty();
});

it('returns null and warns instead of crashing when up() is missing', function () {
    $warnings = [];

    $file = (new MigrationParser)->parse(
        __DIR__.'/../../Fixtures/edge-cases/no_up_method.php',
        function (string $w) use (&$warnings) {
            $warnings[] = $w;
        },
    );

    expect($file)->toBeNull()
        ->and($warnings)->not->toBeEmpty();
});

it('returns null and warns instead of crashing on a missing file', function () {
    $warnings = [];

    $file = (new MigrationParser)->parse(
        __DIR__.'/../../Fixtures/edge-cases/does_not_exist.php',
        function (string $w) use (&$warnings) {
            $warnings[] = $w;
        },
    );

    expect($file)->toBeNull()
        ->and($warnings)->not->toBeEmpty();
});

it('resolves the table from Schema::connection(...)->table(...)', function () {
    $file = (new MigrationParser)->parse(
        __DIR__.'/../../Fixtures/edge-cases/schema_connection.php',
        fn (string $w) => throw new RuntimeException("Unexpected parse warning: {$w}"),
    );

    expect($file)->not->toBeNull()
        ->and($file->operations)->toHaveCount(1)
        ->and($file->operations[0]->table)->toBe('orders')
        ->and($file->operations[0]->type)->toBe('add_index');
});
