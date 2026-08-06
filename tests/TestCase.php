<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RGInfotech\SafeSchema\SafeSchemaServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SafeSchemaServiceProvider::class,
        ];
    }
}
