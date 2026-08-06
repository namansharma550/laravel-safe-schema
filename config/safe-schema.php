<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Strict mode
    |--------------------------------------------------------------------------
    |
    | When false (the default), `migrate:lint` prints violations but always
    | exits 0. Teams opt into a failing build via --strict or by flipping
    | this to true.
    |
    */

    'strict' => false,

    /*
    |--------------------------------------------------------------------------
    | Minimum row count
    |--------------------------------------------------------------------------
    |
    | Rules that depend on table size stay silent below this threshold. If
    | the row count is unknown (no baseline file, no live connection), the
    | rule still warns but says the size is unknown.
    |
    */

    'min_rows' => 10000,

    /*
    |--------------------------------------------------------------------------
    | Disabled rules
    |--------------------------------------------------------------------------
    |
    | Rule ids listed here never run, regardless of driver.
    |
    */

    'disabled_rules' => [],

    /*
    |--------------------------------------------------------------------------
    | Baseline file
    |--------------------------------------------------------------------------
    |
    | Path to the table-size baseline written by `migrate:lint:baseline`.
    |
    */

    'baseline_path' => base_path('.safe-schema-sizes.json'),

    /*
    |--------------------------------------------------------------------------
    | Server version fallback
    |--------------------------------------------------------------------------
    |
    | Used when no live database connection is available to run
    | `SELECT version()`. Version-dependent rules fall back to the
    | conservative assumption when this is also unset.
    |
    */

    'server_version' => null,

];
