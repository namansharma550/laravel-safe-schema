# Contributing

The most valuable contribution to this package is a new rule. This doc walks
through adding one end to end, using the existing `index-not-concurrent` rule as
the worked example. If you're fixing a bug or adding something else, the same
"run the tests before you touch anything, run them again before you open a PR"
expectation applies — see [Development](#development) below.

## Adding a rule

Every rule is: one class, two fixture directories, one unit test, and — where the
danger is a real lock, not a mid-deploy breakage or a code-smell — one integration
test proving the claim against a real database.

### 1. Decide what the rule detects and where it lives

- `src/Rules/Postgres/` — Postgres-specific locking behavior
- `src/Rules/MySQL/` — MySQL-specific locking behavior
- `src/Rules/Generic/` — applies to both drivers (schema-drift risks, mid-deploy
  breakage, code smells — not locking claims)

Check `src/Analysis/Operation.php` and `src/Analysis/BlueprintWalker.php` first.
Every `$table->method()` call already becomes an `Operation` with a `type` (see
`BlueprintWalker::classifyType()`), a `methodChain`, and per-method `args_by_method`.
If your rule can be expressed as "this operation type, with these methods/args, on
a table above the size threshold," you don't need to touch the parser at all —
only the rule class.

If your rule needs something the current `Operation` model doesn't capture (a raw
SQL pattern, a whole-file property like `App\Models\*` usage, or a count across
multiple operations), look at how `MigrationParser::extractModelReferences()`,
`extractBackfillLoops()`, and the `ddlPosition`/`ddlGroupLine` fields solve that —
they're the existing precedent for "operation model doesn't fit."

### 2. Write the rule class

```php
<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Rules\Postgres;

use RGInfotech\SafeMigrations\Analysis\Operation;
use RGInfotech\SafeMigrations\Context\LintContext;
use RGInfotech\SafeMigrations\Results\Violation;
use RGInfotech\SafeMigrations\Rules\Concerns\ChecksTableSize;
use RGInfotech\SafeMigrations\Rules\Rule;

final class IndexNotConcurrentRule implements Rule
{
    use ChecksTableSize; // only if the danger scales with table size

    public function id(): string
    {
        return 'index-not-concurrent'; // kebab-case, stable - this is a public API
    }

    public function drivers(): array
    {
        return ['pgsql'];
    }

    public function appliesTo(Operation $operation): bool
    {
        return in_array($operation->type, ['add_index', 'add_unique'], true);
    }

    public function check(Operation $operation, LintContext $context): ?Violation
    {
        $gate = $this->sizeGate($operation, $context);

        if ($gate === null) {
            return null; // below the size threshold - stay silent
        }

        [$rows, $unknown] = $gate;

        return new Violation(
            ruleId: $this->id(),
            line: $operation->line,
            table: $operation->table,
            message: '...explain the actual lock/breakage, not just "this is bad"...',
            safeAlternative: $this->safeAlternative($operation),
            estimatedRows: $rows,
            sizeUnknown: $unknown,
        );
    }

    public function safeAlternative(Operation $operation): string
    {
        return '...the concrete code a developer should write instead...';
    }
}
```

If your rule has no size dependency (`concurrent-in-transaction`,
`rename-or-drop-column`, the three `Generic` rules), skip the `ChecksTableSize`
trait and either return `?Violation` normally, or — if `appliesTo()` already
confirms every condition needed — narrow `check()`'s return type to `Violation`
(see `ConcurrentInTransactionRule` for the pattern; PHPStan level 6 will tell you
if the narrower type is wrong).

### 3. Register it

Add the class to `SafeMigrationsServiceProvider::defaultRules()`.

### 4. Write fixtures

```
tests/Fixtures/<rule-id>/dangerous/<descriptive_name>.php
tests/Fixtures/<rule-id>/safe/<descriptive_name>.php
tests/Fixtures/<rule-id>/safe/<another_true_negative>.php
```

At least one `dangerous/` fixture and **two** `safe/` fixtures — one that's safe
because of the rule's own logic, and one that's safe because it's below the size
threshold (if the rule is size-gated) or because the intra-file `create()` guard
applies. Real migration syntax, not synthetic. Copy the shape of an existing
fixture for the anonymous-class boilerplate.

### 5. Write the unit test

```php
<?php

declare(strict_types=1);

use RGInfotech\SafeMigrations\Rules\Postgres\IndexNotConcurrentRule;

it('flags a non-concurrent index on a large existing table', function () {
    $operations = lintOperationsFor('index-not-concurrent', 'dangerous/add_index_to_orders.php');
    $context = contextWithSizes(['orders' => 8_400_000]);
    $violations = violationsFor(new IndexNotConcurrentRule, $operations, $context);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->ruleId)->toBe('index-not-concurrent');
});

it('stays silent when the table is below the size threshold', function () {
    $operations = lintOperationsFor('index-not-concurrent', 'safe/index_below_threshold.php');
    $context = contextWithSizes(['small_lookup_table' => 42]);
    $violations = violationsFor(new IndexNotConcurrentRule, $operations, $context);

    expect($violations)->toHaveCount(0);
});
```

`lintOperationsFor()`, `contextWithSizes()`, and `violationsFor()` are shared test
helpers in `tests/Helpers.php` — don't redefine them per file.

### 6. If the danger is a real lock: prove it

This is what makes the package credible instead of just plausible. If your rule's
claim is "this takes lock X, which blocks Y" — not a schema-drift or mid-deploy
risk, an actual lock — add an integration test under `tests/Integration/Postgres/`
or `tests/Integration/MySQL/` that:

1. Creates a real table (seed enough rows to be measurable if relevant)
2. Opens a second, independent connection holding a long-running write or
   transaction
3. Runs the dangerous operation and shows it queues behind that second connection
   (via a short `lock_timeout`/`lock_wait_timeout` and asserting the specific
   timeout error, not a hang)
4. Runs the *safe alternative* and shows it does **not** queue behind the same
   connection

See `tests/Integration/Postgres/IndexNotConcurrentLockTest.php` for the full
pattern, including the env-var-driven connection config so it works both in CI
(where `.github/workflows/tests.yml` starts real Postgres/MySQL services) and
locally if you happen to have a database reachable.

**If you can't verify this locally**, say so in the PR description rather than
guessing at lock behavior from documentation alone — a wrong claim about what
locks are compatible is worse than an unverified rule with the gap called out. The
existing `mysql-multi-ddl` and `SafeMigration` helper tests were both corrected
mid-development by exactly this kind of real-database feedback; guessing about
lock semantics from the docs alone has a real failure rate.

### 7. Run everything before opening a PR

```bash
composer test      # vendor/bin/pest (Unit + Feature only - Integration needs a real DB)
composer analyse    # vendor/bin/phpstan analyse - level 6, zero baseline entries
composer format     # vendor/bin/pint
```

If you have a local Postgres or MySQL/MariaDB reachable, also run
`vendor/bin/pest tests/Integration` with the `SAFE_MIGRATIONS_TEST_*` env vars set
(see the top of any file under `tests/Integration/` for the exact names) — CI runs
this automatically, but catching a failure locally is faster than waiting on CI.

## Development

Clone the repo, then:

```bash
composer install
composer test
composer analyse
composer format
```

PHPStan runs at level 6 with **zero baseline entries** — new code should not need
one either. If PHPStan flags something and the fix isn't obvious, that's usually
a sign of a real type issue, not a false positive; read the linked error-identifier
page before reaching for a workaround.

## Non-goals

Before proposing something outside the rule system, check
[`BUILD_PROMPT.md`](BUILD_PROMPT.md) §2 for what's explicitly out of scope for
this package (SQLite/SQL Server rules, a web UI, auto-fixing existing migrations,
running migrations for you beyond what `SafeMigration`'s helpers execute).
