# Laravel Safe Migrations

It's 2:14pm on a Tuesday. Someone runs `php artisan migrate` to add an index to
`orders` — a one-line, reviewed, entirely reasonable-looking change. Ninety seconds
later, every request touching `orders` is queued. The connection pool fills.
On-call gets paged. The migration itself never showed up as slow in any test —
`orders` has 40 rows in staging.

Here's what actually happened: a slow analytics query was already running against
`orders` when the migration started. In PostgreSQL, locks are granted **FIFO**. The
migration's `CREATE INDEX` requested a lock and queued behind that query. Every
*new* query that arrived after — including the ordinary ones powering the app —
then queued behind the migration. One slow `SELECT` plus a "fast" `ALTER TABLE`
took the whole application down.

Nobody wrote bad code. The migration was correct. It just wasn't *safe*, and
nothing in the review caught that — because the danger isn't visible in the diff,
it's in how Postgres locks work under concurrent load.

Ruby on Rails has [`strong_migrations`](https://github.com/ankane/strong_migrations)
for this. Laravel didn't — until now.

## What it does

`migrate:lint` reads your migration files (via static AST analysis — no database
connection required) and flags operations that take dangerous locks, with the safe
alternative printed right next to the problem:

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

Point it at a live database and it reads real table sizes (via planner estimates,
never `COUNT(*)`), so warnings only fire when the table is actually big enough to
matter.

## Installation

```bash
composer require --dev rginfotech/laravel-safe-migrations
```

The service provider registers automatically via Laravel package discovery.

## Usage

```bash
php artisan migrate:lint
```

By default this scans `database/migrations`, prints any violations, and **exits 0**
— it's advisory out of the box so a first run never breaks your build. Opt into a
failing build with `--strict` or `config('safe-migrations.strict')`.

### Options

| Option | What it does |
|---|---|
| `--new-only` | Only lint migrations not yet recorded in the `migrations` table |
| `--since=origin/main` | Only lint files changed since a git ref |
| `--strict` | Exit non-zero when violations are found |
| `--format=console\|github\|json` | Output format. `github` emits `::warning file=...,line=...::` workflow annotations that show up inline on your PR diff |
| `--path=` | Directory to scan (default: `database/migrations`) |

### In CI

```yaml
- uses: rginfotech/laravel-safe-migrations@v1
  with:
    since: origin/main
```

This installs your app's Composer dependencies and runs `migrate:lint --format=github`
against files changed in the current PR, so violations show up as inline annotations
on the diff. Add `strict: true` to fail the workflow on a violation instead of just
annotating it — see [`action.yml`](action.yml) for every input.

### Silencing a specific violation

```php
// safe-migrations:ignore index-not-concurrent
$table->index('status');
```

Or silence an entire file:

```php
// safe-migrations:ignore-file
```

### Table sizes

Rules that depend on table size stay silent below `config('safe-migrations.min_rows')`
(default 10,000 rows). If the size is unknown, the rule still warns but says so.

Table size resolution order: **baseline file → live database connection → unknown**.

Generate a baseline from a read replica so CI knows your table sizes without touching
production:

```bash
php artisan migrate:lint:baseline --connection=pgsql_replica
```

This reads planner estimates — never `COUNT(*)` — and writes `.safe-migrations-sizes.json`
at your project root. Commit it to your repo:

```json
{
    "generated_at": "2026-08-06T10:00:00Z",
    "driver": "pgsql",
    "server_version": "16.2",
    "tables": { "orders": 8400000, "users": 240000 }
}
```

Without a baseline file and without a reachable database connection, every table's
size is treated as unknown, and rules still warn — they just can't tell you the row
count.

## Rules

### PostgreSQL

| Rule | Danger |
|---|---|
| `index-not-concurrent` | A plain `CREATE INDEX` blocks all writes to the table until it finishes |
| `add-foreign-key` | Takes an `ACCESS EXCLUSIVE` lock on both tables while validating every row |
| `set-not-null` | Forces a full table scan under an exclusive lock |
| `change-column-type` | Usually forces a full table rewrite |
| `volatile-default` | A non-constant default (`now()`, a subquery) forces a rewrite even on PG 11+; before PG 11, even a constant default did |
| `concurrent-in-transaction` | `CREATE INDEX CONCURRENTLY` inside a transaction hard-errors in Postgres |

`volatile-default` and `set-not-null` adapt to the connected server's detected
Postgres version rather than always assuming the newest behavior.

### MySQL

| Rule | Danger |
|---|---|
| `mysql-multi-ddl` | MySQL has no transactional DDL — if the 2nd of 2+ DDL statements in one migration fails, the 1st has already committed with no rollback |

### Driver-agnostic (PostgreSQL + MySQL)

| Rule | Danger |
|---|---|
| `eloquent-in-migration` | An `App\Models\*` reference inside a migration drifts from the schema as the model evolves, breaking the migration months later |
| `backfill-in-migration` | An update/insert loop inside `up()` holds the migration's transaction and locks open for the whole backfill |
| `rename-or-drop-column` | Not a locking issue — breaks *running* application code mid-deploy. Requires expand/contract across separate deploys |

Every rule has unit test coverage (true-positive and true-negative fixtures).
`index-not-concurrent` and `mysql-multi-ddl` additionally have integration tests
that prove their claims against real Postgres and MySQL servers in CI — a
concurrent writer really does block a plain `CREATE INDEX` and not
`CREATE INDEX CONCURRENTLY`, and a failed second DDL statement really does leave
the first one committed. The same harness pattern is planned to extend to the
remaining rules.

## Safe migration helpers

Linting tells you a migration is dangerous. `SafeMigration` writes the safe one for
you:

```php
use RGInfotech\SafeMigrations\SafeMigration;

return new class extends Migration {
    use SafeMigration;

    public $withinTransaction = false; // required for addIndexConcurrently on pgsql

    public function up(): void
    {
        $this->addIndexConcurrently('orders', ['status']);
        $this->addForeignKeyDeferred('orders', 'user_id', 'users');
        $this->setNotNullSafely('orders', 'status', columnDefinition: 'VARCHAR(50)');
    }
};
```

Each helper runs the correct multi-statement sequence for the connected driver, sets
a lock-timeout before acquiring, and retries with exponential backoff if it queues
behind another lock holder:

| Helper | Postgres | MySQL |
|---|---|---|
| `addIndexConcurrently` | `CREATE INDEX CONCURRENTLY` | `ALTER ... ALGORITHM=INPLACE, LOCK=NONE` |
| `addForeignKeyDeferred` | `ADD CONSTRAINT ... NOT VALID`, then a separate `VALIDATE CONSTRAINT` | Plain `ADD CONSTRAINT ... FOREIGN KEY` — MySQL/MariaDB reject `ALGORITHM=INPLACE` for foreign key additions, so this runs without an algorithm hint and lets the server pick |
| `setNotNullSafely` | A validated `CHECK` constraint first (PG 12+), so `SET NOT NULL` skips its own rescan; falls back to a plain `SET NOT NULL` before PG 12 | `MODIFY COLUMN ... NOT NULL, ALGORITHM=INPLACE, LOCK=NONE` |

If `addIndexConcurrently` fails or times out on Postgres partway through, it leaves
behind an invalid index rather than retrying blindly into a confusing "already
exists" error — each retry attempt checks for and drops a leftover invalid index of
the same name first.

Every helper is verified against a real database, not just asserted: the test suite
creates real tables, runs each helper, and confirms the resulting index/constraint/
column actually behaves as claimed (index is valid, foreign key rejects bad
references, `NOT NULL` rejects nulls).

## What this doesn't do (yet)

- MySQL-specific lock-danger *lint rules* (index/foreign-key/column-type
  equivalents of the Postgres rules above) — MySQL's locking model differs enough
  from Postgres's that these need their own design, not a straight port
- Auto-fixing *existing* migration files — `SafeMigration` helps you write new
  migrations safely, but the linter itself only reads and reports
- Running migrations for you — `SafeMigration`'s helpers execute DDL, but only the
  statements you explicitly call inside `up()`

## Configuration

```bash
php artisan vendor:publish --tag=safe-migrations-config
```

```php
return [
    'strict' => false,
    'min_rows' => 10000,
    'disabled_rules' => [],
    'baseline_path' => base_path('.safe-migrations-sizes.json'),
    'server_version' => null,
];
```

## Contributing

Adding a rule is one class, two fixture directories, and a test — see
[`CONTRIBUTING.md`](CONTRIBUTING.md) for the full walkthrough.

## License

[MIT](LICENSE.md)
