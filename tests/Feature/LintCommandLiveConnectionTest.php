<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('resolves live table sizes end-to-end when no baseline file exists', function () {
    config(['database.default' => 'mysql_test']);
    config(['database.connections.mysql_test' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'safe_migrations_test',
        'username' => 'root',
        'password' => '',
    ]]);

    try {
        DB::connection('mysql_test')->getPdo();
    } catch (Throwable $e) {
        test()->markTestSkipped('No local MySQL/MariaDB reachable: '.$e->getMessage());
    }

    config(['safe-migrations.baseline_path' => sys_get_temp_dir().'/does-not-exist-'.uniqid().'.json']);

    // No MySQL rules exist yet (that's Milestone 3), so this only proves the
    // command builds a live-backed LintContext without crashing and without
    // finding any violations (nothing is registered for the mysql driver).
    $dir = sys_get_temp_dir().'/safe-migrations-live-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/2026_01_01_000000_add_index.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('status');
        });
    }
};
PHP);

    $exitCode = Artisan::call('migrate:lint', ['--path' => $dir]);

    expect($exitCode)->toBe(0);

    unlink($dir.'/2026_01_01_000000_add_index.php');
    rmdir($dir);
});
