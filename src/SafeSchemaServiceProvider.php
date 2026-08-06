<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema;

use Illuminate\Support\ServiceProvider;
use RGInfotech\SafeSchema\Console\BaselineCommand;
use RGInfotech\SafeSchema\Console\LintCommand;
use RGInfotech\SafeSchema\Rules\Generic\BackfillInMigrationRule;
use RGInfotech\SafeSchema\Rules\Generic\EloquentInMigrationRule;
use RGInfotech\SafeSchema\Rules\Generic\RenameOrDropColumnRule;
use RGInfotech\SafeSchema\Rules\MySQL\MysqlMultiDdlRule;
use RGInfotech\SafeSchema\Rules\Postgres\AddForeignKeyRule;
use RGInfotech\SafeSchema\Rules\Postgres\ChangeColumnTypeRule;
use RGInfotech\SafeSchema\Rules\Postgres\ConcurrentInTransactionRule;
use RGInfotech\SafeSchema\Rules\Postgres\IndexNotConcurrentRule;
use RGInfotech\SafeSchema\Rules\Postgres\SetNotNullRule;
use RGInfotech\SafeSchema\Rules\Postgres\VolatileDefaultRule;
use RGInfotech\SafeSchema\Rules\Rule;
use RGInfotech\SafeSchema\Rules\RuleRegistry;

class SafeSchemaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/safe-schema.php', 'safe-schema');

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
                __DIR__.'/../config/safe-schema.php' => config_path('safe-schema.php'),
            ], 'safe-schema-config');

            $this->commands([
                LintCommand::class,
                BaselineCommand::class,
            ]);
        }
    }
}
