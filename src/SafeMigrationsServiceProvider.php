<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations;

use Illuminate\Support\ServiceProvider;
use RGInfotech\SafeMigrations\Console\BaselineCommand;
use RGInfotech\SafeMigrations\Console\LintCommand;
use RGInfotech\SafeMigrations\Rules\Generic\BackfillInMigrationRule;
use RGInfotech\SafeMigrations\Rules\Generic\EloquentInMigrationRule;
use RGInfotech\SafeMigrations\Rules\Generic\RenameOrDropColumnRule;
use RGInfotech\SafeMigrations\Rules\MySQL\MysqlMultiDdlRule;
use RGInfotech\SafeMigrations\Rules\Postgres\AddForeignKeyRule;
use RGInfotech\SafeMigrations\Rules\Postgres\ChangeColumnTypeRule;
use RGInfotech\SafeMigrations\Rules\Postgres\ConcurrentInTransactionRule;
use RGInfotech\SafeMigrations\Rules\Postgres\IndexNotConcurrentRule;
use RGInfotech\SafeMigrations\Rules\Postgres\SetNotNullRule;
use RGInfotech\SafeMigrations\Rules\Postgres\VolatileDefaultRule;
use RGInfotech\SafeMigrations\Rules\Rule;
use RGInfotech\SafeMigrations\Rules\RuleRegistry;

class SafeMigrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/safe-migrations.php', 'safe-migrations');

        $this->app->singleton(RuleRegistry::class, function (): RuleRegistry {
            $registry = new RuleRegistry;

            foreach ($this->defaultRules() as $rule) {
                $registry->register($rule);
            }

            return $registry;
        });
    }

    /**
     * @return list<Rule>
     */
    private function defaultRules(): array
    {
        return [
            new IndexNotConcurrentRule,
            new AddForeignKeyRule,
            new SetNotNullRule,
            new ChangeColumnTypeRule,
            new VolatileDefaultRule,
            new ConcurrentInTransactionRule,
            new EloquentInMigrationRule,
            new BackfillInMigrationRule,
            new RenameOrDropColumnRule,
            new MysqlMultiDdlRule,
        ];
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/safe-migrations.php' => config_path('safe-migrations.php'),
            ], 'safe-migrations-config');

            $this->commands([
                LintCommand::class,
                BaselineCommand::class,
            ]);
        }
    }
}
