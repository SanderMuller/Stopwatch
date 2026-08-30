<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\Integrations;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Fruitcake\LaravelDebugbar\ServiceProvider as DebugbarServiceProvider;
use Illuminate\Foundation\Application;
use SanderMuller\Stopwatch\Integrations\DebugbarCollector;
use SanderMuller\Stopwatch\ServiceProvider;
use SanderMuller\Stopwatch\Tests\TestCase;

final class DebugbarRegistrationTest extends TestCase
{
    private bool $forceEnable = true;

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', true);
        $app['config']->set('debugbar.enabled', true);
        $app['config']->set('debugbar.force_allow_enable', true);

        if (! $this->forceEnable) {
            return;
        }

        // isEnabled() is always false under phpunit (runningInConsole), so flip
        // the flag directly before the package provider boots.
        $app->resolving(LaravelDebugbar::class, static function (LaravelDebugbar $debugBar): void {
            $debugBar->enable();
        });
    }

    /**
     * @param Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DebugbarServiceProvider::class, ServiceProvider::class];
    }

    public function test_collector_is_registered_on_debugbar_v4(): void
    {
        $debugBar = $this->app->make(LaravelDebugbar::class);

        self::assertTrue($debugBar->hasCollector(DebugbarCollector::NAME));
        self::assertInstanceOf(DebugbarCollector::class, $debugBar->getCollector(DebugbarCollector::NAME));
    }

    public function test_collector_is_skipped_while_debugbar_is_disabled(): void
    {
        $this->forceEnable = false;
        $this->refreshApplication();

        self::assertFalse($this->app->make(LaravelDebugbar::class)->hasCollector(DebugbarCollector::NAME));
    }
}
