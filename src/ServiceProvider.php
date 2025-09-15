<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class ServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('stopwatch');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Stopwatch::class, function (): Stopwatch {
            return Stopwatch::new();
        });
    }
}
