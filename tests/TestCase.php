<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SanderMuller\Stopwatch\ServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }
}
