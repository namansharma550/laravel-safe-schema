<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

function runGit(array $args, string $cwd): void
{
    $process = new Process(['git', ...$args], $cwd);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException('git command failed: '.$process->getErrorOutput());
    }
}

function migrationContents(): string
{
    return <<<'PHP'
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
PHP;
}

beforeEach(function () {
    config(['database.default' => 'pgsql']);
    config(['database.connections.pgsql' => ['driver' => 'pgsql']]);
    config(['safe-migrations.baseline_path' => sys_get_temp_dir().'/does-not-exist-'.uniqid().'.json']);

    $this->repo = sys_get_temp_dir().'/safe-migrations-since-'.uniqid();
    mkdir($this->repo);
    mkdir($this->repo.'/database');
    mkdir($this->repo.'/database/migrations');

    runGit(['init', '--initial-branch=main'], $this->repo);
    runGit(['config', 'user.email', 'test@example.com'], $this->repo);
    runGit(['config', 'user.name', 'Test'], $this->repo);

    // An existing, already-committed migration should be excluded by --since.
    file_put_contents($this->repo.'/database/migrations/2020_01_01_000000_old.php', migrationContents());
    runGit(['add', '.'], $this->repo);
    runGit(['commit', '-m', 'initial'], $this->repo);

    // A new migration added after that commit should be included by --since=main.
    file_put_contents($this->repo.'/database/migrations/2026_01_01_000000_new.php', migrationContents());
    runGit(['add', '.'], $this->repo);
    runGit(['commit', '-m', 'add new migration'], $this->repo);
});

afterEach(function () {
    (new Process(['rm', '-rf', $this->repo]))->run();
});

it('only lints files changed since the given git ref', function () {
    $exitCode = Artisan::call('migrate:lint', [
        '--path' => $this->repo.'/database/migrations',
        '--since' => 'HEAD~1',
        '--format' => 'json',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['summary']['files_scanned'])->toBe(1)
        ->and($decoded['violations'][0]['file'])->toContain('2026_01_01_000000_new.php');
});
