<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Illuminate\Support\Facades\Blade;
use SanderMuller\Stopwatch\Notifications\StopwatchNotificationChannel;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class ServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('stopwatch')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Stopwatch::class, function (): Stopwatch {
            return $this->configureStopwatch(Stopwatch::new());
        });
    }

    public function packageBooted(): void
    {
        Blade::directive('stopwatch', function (): string {
            return '<?php echo app(' . Stopwatch::class . '::class)->render(); ?>';
        });
    }

    /** @phpstan-ignore complexity.functionLike */
    private function configureStopwatch(Stopwatch $stopwatch): Stopwatch
    {
        /** @var array<string, mixed> $config */
        $config = config('stopwatch');

        if (($config['enabled'] ?? true) === false) {
            $stopwatch->disable();

            return $stopwatch;
        }

        $outputEnum = is_string($config['output'] ?? null)
            ? StopwatchOutput::tryFrom($config['output'])
            : null;

        if ($outputEnum instanceof StopwatchOutput) {
            $stopwatch->outputTo($outputEnum);
        }

        if (is_string($config['log_level'] ?? null)) {
            $stopwatch->setLogLevel($config['log_level']);
        }

        if (is_int($config['slow_threshold'] ?? null)) {
            $stopwatch->slowCheckpointThreshold($config['slow_threshold']);
        }

        if (($config['track_queries'] ?? false) === true) {
            $stopwatch->withQueryTracking();
        }

        if (($config['track_memory'] ?? false) === true) {
            $stopwatch->withMemoryTracking();
        }

        /** @var array<class-string<StopwatchNotificationChannel>> $channels */
        $channels = $config['notification_channels'] ?? [];

        if ($channels !== []) {
            $stopwatch->notifyUsing($channels);
        }

        return $stopwatch;
    }
}
