<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Override;
use SanderMuller\Stopwatch\Integrations\DebugbarRegistrar;
use SanderMuller\Stopwatch\Notifications\StopwatchNotificationChannel;
use SanderMuller\Stopwatch\RunLog\RunLogServiceRegistrar;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class ServiceProvider extends PackageServiceProvider
{
    #[Override]
    public function configurePackage(Package $package): void
    {
        $package
            ->name('stopwatch')
            ->hasConfigFile()
            ->hasCommands([
                Console\RunsListCommand::class,
                Console\RunsShowCommand::class,
                Console\RunsClearCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        RunLogServiceRegistrar::register($this->app);

        $this->app->singleton(Stopwatch::class, function (): Stopwatch {
            return $this->configureStopwatch(Stopwatch::new());
        });
    }

    public function packageBooted(): void
    {
        Blade::directive('stopwatch', function (): string {
            return '<?php echo app(' . Stopwatch::class . '::class)->render(); ?>';
        });

        $this->registerInjectAlias();

        InjectMiddlewareRegistrar::register($this->app);
        DebugbarRegistrar::register($this->app);
    }

    private function registerInjectAlias(): void
    {
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware(StopwatchInjectMiddleware::ALIAS, StopwatchInjectAlias::class);
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

        if (($config['track_http'] ?? false) === true) {
            $stopwatch->withHttpTracking();
        }

        /** @var array<class-string<StopwatchNotificationChannel>> $channels */
        $channels = $config['notification_channels'] ?? [];

        if ($channels !== []) {
            $stopwatch->notifyUsing($channels);
        }

        if (is_numeric($config['notify_threshold'] ?? null)) {
            $stopwatch->notifyIfSlowerThan((int) $config['notify_threshold']);
        }

        RunLogServiceRegistrar::wire($this->app, $stopwatch);

        return $stopwatch;
    }
}
