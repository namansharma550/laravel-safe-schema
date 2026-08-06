<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
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

    DB::connection('mysql_test')->statement('DROP TABLE IF EXISTS baseline_probe');
    DB::connection('mysql_test')->statement('CREATE TABLE baseline_probe (id INT PRIMARY KEY)');

    $this->outputPath = sys_get_temp_dir().'/safe-schema-baseline-'.uniqid().'.json';
});

afterEach(function () {
    DB::connection('mysql_test')->statement('DROP TABLE IF EXISTS baseline_probe');

    if (is_file($this->outputPath)) {
        unlink($this->outputPath);
    }
});

it('writes a baseline file with real table sizes from a live connection', function () {
    $exitCode = Artisan::call('migrate:lint:baseline', [
        '--connection' => 'mysql_test',
        '--output' => $this->outputPath,
    ]);

    expect($exitCode)->toBe(0)
        ->and(is_file($this->outputPath))->toBeTrue();

    $decoded = json_decode(file_get_contents($this->outputPath), true);

    expect($decoded)->toHaveKeys(['generated_at', 'driver', 'server_version', 'tables'])
        ->and($decoded['driver'])->toBe('mysql')
        ->and($decoded['server_version'])->not->toBeNull()
        ->and($decoded['tables'])->toHaveKey('baseline_probe');
});

it('fails cleanly when the connection is unreachable', function () {
    config(['database.connections.mysql_unreachable' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'does_not_exist',
        'username' => 'nobody',
        'password' => 'nobody',
    ]]);

    $exitCode = Artisan::call('migrate:lint:baseline', [
        '--connection' => 'mysql_unreachable',
        '--output' => $this->outputPath,
    ]);

    expect($exitCode)->toBe(1)
        ->and(is_file($this->outputPath))->toBeFalse();
});
