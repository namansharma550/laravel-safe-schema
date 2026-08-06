# Build Prompt: Laravel Safe Migration Linter

> Paste this whole document as the opening message to your coding agent.
> Work through the milestones in order. Do not skip ahead.

---

## 0. Your role and how to work

You are building an open-source Laravel package from scratch. I want a
production-quality, well-tested package — not a prototype.

Rules of engagement:

- **Build in the milestone order given.** Finish and test each milestone before
  starting the next. Show me the diff at the end of each one.
- **Do not trust the database claims in this document.** Every statement about
  locking behaviour must be verified by an integration test against a real
  database container before the corresponding rule ships. If a claim here turns
  out to be wrong, tell me and correct the rule.
- **Ask before inventing scope.** If something is ambiguous, ask rather than
  guessing at a design.
- **No placeholder code.** No `// TODO: implement`, no stub methods that return
  hardcoded values. If a milestone is too large, tell me and we'll split it.
- Run the test suite after every meaningful change.

---

## 1. What we're building and why

Laravel developers regularly take production down with migrations. A migration
that looks harmless — adding an index, adding a foreign key, changing a column
type — acquires an exclusive lock on a large table and the application stalls
behind it.

The failure mode most people misunderstand: in PostgreSQL, locks are granted
**FIFO**. When a migration requests `ACCESS EXCLUSIVE`, it waits behind any
in-flight query on that table. Every *new* query that arrives then queues behind
the migration. So one slow analytics SELECT plus a "fast" `ALTER TABLE` equals a
full application stall and connection-pool exhaustion.

Ruby on Rails has `strong_migrations` for this. **Laravel has no equivalent.**

We are building it, plus one thing `strong_migrations` doesn't do: reading real
table sizes so warnings only fire when the table is actually big enough to matter.

**Package name:** `<VENDOR>/laravel-safe-migrations`
**Namespace:** `<Vendor>\SafeMigrations\`
(I'll give you the vendor name; ask if I haven't.)

---

## 2. Hard constraints

```json
{
  "require": {
    "php": "^8.2",
    "illuminate/console": "^12.0|^13.0",
    "illuminate/database": "^12.0|^13.0",
    "illuminate/support": "^12.0|^13.0",
    "nikic/php-parser": "^5.0"
  },
  "require-dev": {
    "orchestra/testbench": "^10.0|^11.0",
    "pestphp/pest": "^3.0",
    "larastan/larastan": "^3.0",
    "laravel/pint": "^1.0"
  }
}
```

- Depend on **`illuminate/*` components**, never `laravel/framework`.
- Register via `extra.laravel.providers` for package auto-discovery.
- PHPStan at **level 6 minimum**, level 8 preferred. Zero baseline entries.
- CI matrix: PHP 8.2/8.3/8.4 × Laravel 12/13, **excluding PHP 8.2 + Laravel 13**
  (Laravel 13 requires PHP 8.3+).

### Non-goals for v1 — do not build these

- SQLite/SQL Server/MariaDB-specific rules. SQLite is an explicit no-op with a
  clear message.
- Any web UI or dashboard.
- Auto-fixing or rewriting migration files.
- Running migrations. We only read and analyse them.

---

## 3. Architecture

Static analysis is the **default and primary** mode. It must run in CI with **no
database connection**, because requiring DB credentials in CI kills adoption.
Database connection is an optional accuracy upgrade.

```
src/
  SafeMigrationsServiceProvider.php
  Console/
    LintCommand.php            # migrate:lint
    BaselineCommand.php        # migrate:lint:baseline
  Analysis/
    MigrationParser.php        # php-parser → AST
    BlueprintWalker.php        # AST → normalised Operation objects
    Operation.php              # DTO: type, table, columns, options, line
    MigrationFile.php          # DTO: path, class, operations, withinTransaction
  Rules/
    Rule.php                   # interface
    RuleRegistry.php
    Concerns/...
    Postgres/*.php
    MySQL/*.php
  Context/
    TableSizeResolver.php      # baseline file OR live estimate OR unknown
    ServerVersion.php          # detect + compare
    LintContext.php            # driver, version, sizes, config
  Results/
    Violation.php              # rule id, severity, file, line, message, fix
    LintResult.php
  Output/
    ConsoleReporter.php
    GithubReporter.php         # ::warning file=...,line=...::
    JsonReporter.php
  Support/
    IgnoreAnnotations.php
```

**Pipeline:** discover files → parse to AST → walk into `Operation[]` → build
`LintContext` → run rules → filter ignores → report → exit code.

### Parsing notes

- Use `nikic/php-parser` v5. Locate `Schema::create()` / `Schema::table()` /
  `Schema::connection(...)->...` calls inside the `up()` method.
- Walk the `Blueprint` closure and record every method call on the `$table`
  parameter, including full chains (`$table->string('x')->nullable()->change()`).
- Capture **line numbers** — they're needed for editor and CI annotations.
- Detect `public $withinTransaction = false;` on the migration class.
- Also detect anonymous-class migrations (`return new class extends Migration`),
  which is the Laravel 12/13 default.
- If a file can't be parsed, **skip it with a warning**. Never crash the command.

---

## 4. Rules

Each rule is a class implementing:

```php
interface Rule
{
    public function id(): string;              // 'index-not-concurrent'
    public function drivers(): array;          // ['pgsql']
    public function appliesTo(Operation $op): bool;
    public function check(Operation $op, LintContext $ctx): ?Violation;
    public function safeAlternative(Operation $op): string;
}
```

### Milestone 1 rule set (PostgreSQL)

| id | Trigger | Danger | Safe alternative |
|---|---|---|---|
| `index-not-concurrent` | `$table->index()` / `unique()` on existing table | Blocks writes for the whole index build | `$withinTransaction = false;` + `CREATE INDEX CONCURRENTLY` |
| `add-foreign-key` | `$table->foreign()->references()` | `ACCESS EXCLUSIVE` on both tables + full validation scan | `ADD CONSTRAINT ... NOT VALID`, then `VALIDATE CONSTRAINT` in a separate migration |
| `set-not-null` | `->nullable(false)->change()` | Full table scan under exclusive lock | Add `CHECK (col IS NOT NULL) NOT VALID` → `VALIDATE` → `SET NOT NULL` (PG 12+ then skips the rescan) |
| `change-column-type` | `->change()` altering type | Usually a full table rewrite | Add new column, backfill in batches, swap, drop old |
| `volatile-default` | `ADD COLUMN` with a non-constant default | Volatile defaults still force a rewrite even on PG 11+ | Add nullable → backfill → set default |
| `concurrent-in-transaction` | `CONCURRENTLY` in raw SQL while `$withinTransaction` is true | Postgres hard-errors: cannot run inside a transaction block | Set `public $withinTransaction = false;` |

### Milestone 3 additions (driver-agnostic + MySQL)

| id | Trigger | Danger |
|---|---|---|
| `eloquent-in-migration` | Any `App\Models\*` reference inside `up()` | Model drifts from schema; migration breaks months later |
| `backfill-in-migration` | Update/insert loop inside `up()` | Holds the migration transaction open for the whole backfill |
| `rename-or-drop-column` | `renameColumn` / `dropColumn` | Not a lock issue — breaks *running* app code mid-deploy. Requires expand/contract across deploys |
| `mysql-multi-ddl` | 2+ DDL statements in one MySQL migration | MySQL has no transactional DDL; a mid-way failure leaves partial schema |

### Version-dependent behaviour — encode as config, don't hardcode

- `ADD COLUMN` with a **constant** default was a full rewrite before **PG 11**,
  cheap after. Only flag the **volatile** case on PG 11+.
- `SET NOT NULL` can use a validated CHECK constraint to skip the rescan from
  **PG 12** onward.
- MySQL **8.0.12+** supports `ALGORITHM=INSTANT` for adding columns, with
  restrictions (before 8.0.29 the column had to be last; unavailable for
  compressed row format).

Detect via `SELECT version()` in connection mode; fall back to a config value;
**default to the conservative assumption** when unknown.

---

## 5. Adoption safeguards — non-negotiable

If a first run flags 400 historical migrations, the package gets uninstalled in
30 seconds. All of these ship in Milestone 1:

- `--new-only` — lint only migrations absent from the `migrations` table
- `--since=origin/main` — lint only files changed in this branch (via `git diff --name-only`)
- **Intra-file awareness** — if the table was `create()`d in the same migration,
  there is nothing to lock. Stay silent.
- **Size threshold** — no warning below `config('safe-migrations.min_rows')`
  (default `10000`). Unknown size = warn, but say the size is unknown.
- Ignore annotations:
  - `// safe-migrations:ignore index-not-concurrent` (next operation)
  - `// safe-migrations:ignore-file` (whole file)
- Config array to disable rules globally.
- **`strict => false` by default.** Violations print and exit `0`. Teams opt into
  a failing build via `--strict` or config. Ship advisory, let them turn the screws.

---

## 6. Table size resolution

Never run `COUNT(*)`. Use planner estimates.

- **PostgreSQL:** `SELECT reltuples::bigint FROM pg_class WHERE relname = ?`
- **MySQL:** `SELECT table_rows FROM information_schema.tables WHERE table_name = ?`

```bash
php artisan migrate:lint:baseline --connection=pgsql_replica
```

Writes `.safe-migrations-sizes.json` at the project root:

```json
{
  "generated_at": "2026-08-06T10:00:00Z",
  "driver": "pgsql",
  "server_version": "16.2",
  "tables": { "orders": 8400000, "users": 240000 }
}
```

Committed to the repo, so CI knows `orders` is huge without touching production.
Resolution order: **baseline file → live connection → unknown**.

---

## 7. Output

Console (default) must look like this:

```
✗ database/migrations/2026_08_06_add_index_to_orders.php:14

  [index-not-concurrent]  orders (~8,400,000 rows)

  A non-concurrent CREATE INDEX takes a lock that blocks all writes to
  `orders` until the index finishes building. Other queries then queue
  behind it.

  Instead:

    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY idx_orders_status ON orders (status)');
    }

  Silence: // safe-migrations:ignore index-not-concurrent

1 issue in 1 file (3 files scanned, 12 skipped as already migrated)
```

Also implement `--format=github` (workflow annotations, so violations appear
inline on the PR diff) and `--format=json`.

---

## 8. Testing

**Unit / rule tests (Pest + Testbench)** — golden-file style. Each rule gets a
fixture directory with `dangerous/` and `safe/` migration files and an expected
violations snapshot. Every rule needs at least one true-positive and two
true-negative cases.

**Integration harness — this is the credibility of the package.** GitHub Actions
services for real Postgres and MySQL. For each dangerous operation:

1. Create a table, seed enough rows to be measurable
2. Open a concurrent connection holding a long transaction / running writes
3. Execute the operation
4. Assert the observed lock via `pg_locks` (Postgres) or
   `performance_schema.metadata_locks` (MySQL)
5. Assert the *suggested safe alternative* does **not** take that lock

A rule ships only when this test passes. The README should state that every rule
has an integration test proving the lock it claims.

**Also test:** anonymous-class migrations, `Schema::connection()`, unparseable
files, missing baseline file, no DB connection available, empty migrations dir.

---

## 9. Milestones

**M1 — Walking skeleton (Postgres, static only).** Skeleton, service provider,
config, parser, walker, 6 Postgres rules, console reporter, `--new-only`,
`--since`, ignore annotations, size thresholds, unit tests, PHPStan clean, CI
matrix green. *Done when:* `php artisan migrate:lint` gives a useful, quiet,
non-crashing result on a real Laravel app.

**M2 — Sizes + CI output.** Baseline command, live estimates, version detection,
`--format=github` and `--format=json`, integration harness for Postgres.

**M3 — MySQL + remaining rules.** MySQL driver support and rules, the four
driver-agnostic rules, MySQL integration harness.

**M4 — Safe primitives (v0.3).** This is what makes teams keep the package:

```php
return new class extends Migration {
    use SafeMigration;

    public function up(): void {
        $this->addIndexConcurrently('orders', ['status']);
        $this->addForeignKeyDeferred('orders', 'user_id', 'users');
        $this->setNotNullSafely('orders', 'status');
    }
};
```

Each helper performs the correct multi-statement sequence, sets `lock_timeout`
before acquiring, and retries with exponential backoff on lock timeout. Once
migrations are written with these, the package is load-bearing rather than
advisory.

**M5 — v1.0 polish.** GitHub Action wrapper so adoption is three lines of YAML.
`CONTRIBUTING.md` with a step-by-step "how to add a rule" walkthrough (each rule
is one class + fixtures + one integration test — make community contribution
trivial). README opening with the incident narrative, not a feature list.

---

## 10. Definition of done for v0.1

- [ ] `composer require --dev <vendor>/laravel-safe-migrations` works from a clean Laravel 12 app
- [ ] Zero output on a well-written migration
- [ ] Correct, actionable output on each of the 6 dangerous cases
- [ ] Never crashes: no DB, no baseline, unparseable file, SQLite
- [ ] Exits 0 by default, non-zero under `--strict`
- [ ] PHPStan level 6+ with an empty baseline
- [ ] CI green across the full PHP × Laravel matrix
- [ ] README a newcomer can act on in under two minutes

---

## Start here

Confirm the vendor name with me, then begin Milestone 1. Start by scaffolding
from `spatie/package-skeleton-laravel` and showing me the resulting
`composer.json` and directory tree before writing any rule code.
