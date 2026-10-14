<?php

declare(strict_types=1);

namespace Fledge\Apm\Tests;

use Fledge\Apm\ApmServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ApmServiceProvider::class];
    }
}
