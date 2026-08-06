<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

function writeDangerousMigration(string $dir): string
{
    $path = $dir.'/2026_01_01_000000_add_index_to_orders.php';

    file_put_contents($path, <<<'PHP'
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

    return $path;
}

beforeEach(function () {
    config(['database.default' => 'pgsql']);
    config(['database.connections.pgsql' => ['driver' => 'pgsql']]);
    config(['safe-migrations.baseline_path' => sys_get_temp_dir().'/does-not-exist-'.uniqid().'.json']);

    $this->dir = sys_get_temp_dir().'/safe-migrations-formats-'.uniqid();
    mkdir($this->dir);
    writeDangerousMigration($this->dir);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*.php'));
    rmdir($this->dir);
});

it('emits a GitHub workflow-command annotation for each violation', function () {
    $exitCode = Artisan::call('migrate:lint', ['--path' => $this->dir, '--format' => 'github']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('::warning file=')
        ->and($output)->toContain('title=index-not-concurrent')
        ->and($output)->toContain('orders');
});

it('emits valid, well-shaped JSON for violations', function () {
    $exitCode = Artisan::call('migrate:lint', ['--path' => $this->dir, '--format' => 'json']);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toBeArray()
        ->and($decoded['summary']['issue_count'])->toBe(1)
        ->and($decoded['violations'][0]['rule'])->toBe('index-not-concurrent')
        ->and($decoded['violations'][0]['table'])->toBe('orders')
        ->and($decoded['violations'][0]['line'])->toBeInt();
});
