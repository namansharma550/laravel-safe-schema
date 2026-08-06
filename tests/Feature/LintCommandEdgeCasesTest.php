<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('is a no-op on SQLite and never crashes', function () {
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]);

    $this->artisan('migrate:lint', ['--path' => 'database/migrations'])
        ->assertExitCode(0);
});

it('does not crash on a missing migrations directory', function () {
    config(['database.default' => 'pgsql']);
    config(['database.connections.pgsql' => ['driver' => 'pgsql']]);

    $this->artisan('migrate:lint', ['--path' => 'database/does-not-exist'])
        ->assertExitCode(0);
});

it('does not crash on an empty migrations directory', function () {
    config(['database.default' => 'pgsql']);
    config(['database.connections.pgsql' => ['driver' => 'pgsql']]);

    $emptyDir = sys_get_temp_dir().'/safe-schema-empty-'.uniqid();
    mkdir($emptyDir);

    $this->artisan('migrate:lint', ['--path' => $emptyDir])
        ->assertExitCode(0);

    rmdir($emptyDir);
});

it('does not crash when --new-only is requested without a reachable database', function () {
    config(['database.default' => 'pgsql']);
    config(['database.connections.pgsql' => [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 1, // deliberately unreachable
        'database' => 'does_not_exist',
        'username' => 'nobody',
        'password' => 'nobody',
    ]]);

    $emptyDir = sys_get_temp_dir().'/safe-schema-newonly-'.uniqid();
    mkdir($emptyDir);

    $this->artisan('migrate:lint', [
        '--path' => $emptyDir,
        '--new-only' => true,
    ])->assertExitCode(0);

    rmdir($emptyDir);
});

it('falls back to unknown table sizes when no baseline file exists', function () {
    config(['database.default' => 'pgsql']);
    config(['database.connections.pgsql' => ['driver' => 'pgsql']]);
    config(['safe-schema.baseline_path' => sys_get_temp_dir().'/does-not-exist-'.uniqid().'.json']);

    $dir = sys_get_temp_dir().'/safe-schema-nobaseline-'.uniqid();
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

    $exitCode = Artisan::call('migrate:lint', ['--path' => $dir, '--format' => 'json']);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($decoded['violations'][0]['size_unknown'])->toBeTrue()
        ->and($decoded['violations'][0]['estimated_rows'])->toBeNull();

    unlink($dir.'/2026_01_01_000000_add_index.php');
    rmdir($dir);
});
