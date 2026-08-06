<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RGInfotech\SafeMigrations\SafeMigrationsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SafeMigrationsServiceProvider::class,
        ];
    }
}
