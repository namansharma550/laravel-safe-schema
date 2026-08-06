<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    config(['safe-migrations.baseline_path' => sys_get_temp_dir().'/does-not-exist-'.uniqid().'.json']);

    $this->dir = sys_get_temp_dir().'/safe-migrations-generic-'.uniqid();
    mkdir($this->dir);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*.php'));
    rmdir($this->dir);
});

it('flags eloquent-in-migration and backfill-in-migration together on mysql', function () {
    config(['database.default' => 'mysql_pipeline']);
    config(['database.connections.mysql_pipeline' => ['driver' => 'mysql']]);

    file_put_contents($this->dir.'/2026_01_01_000000_backfill.php', <<<'PHP'
<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (Order::all() as $order) {
            $order->update(['status' => 'migrated']);
        }
    }
};
PHP);

    $exitCode = Artisan::call('migrate:lint', ['--path' => $this->dir, '--format' => 'json']);
    $decoded = json_decode(Artisan::output(), true);

    $ruleIds = array_column($decoded['violations'], 'rule');

    expect($exitCode)->toBe(0)
        ->and($ruleIds)->toContain('eloquent-in-migration')
        ->and($ruleIds)->toContain('backfill-in-migration');
});

it('flags mysql-multi-ddl only on the mysql driver, not pgsql', function () {
    file_put_contents($this->dir.'/2026_01_01_000000_multi_ddl.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('a')->nullable();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('b')->nullable();
        });
    }
};
PHP);

    config(['database.default' => 'mysql_pipeline']);
    config(['database.connections.mysql_pipeline' => ['driver' => 'mysql']]);
    $mysqlExitCode = Artisan::call('migrate:lint', ['--path' => $this->dir, '--format' => 'json']);
    $mysqlRuleIds = array_column(json_decode(Artisan::output(), true)['violations'], 'rule');

    config(['database.default' => 'pgsql_pipeline']);
    config(['database.connections.pgsql_pipeline' => ['driver' => 'pgsql']]);
    $pgsqlExitCode = Artisan::call('migrate:lint', ['--path' => $this->dir, '--format' => 'json']);
    $pgsqlRuleIds = array_column(json_decode(Artisan::output(), true)['violations'], 'rule');

    expect($mysqlExitCode)->toBe(0)
        ->and($mysqlRuleIds)->toContain('mysql-multi-ddl')
        ->and($pgsqlExitCode)->toBe(0)
        ->and($pgsqlRuleIds)->not->toContain('mysql-multi-ddl');
});
