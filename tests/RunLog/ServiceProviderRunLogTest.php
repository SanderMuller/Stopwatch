<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use Illuminate\Console\Events\CommandStarting;
use SanderMuller\Stopwatch\RunLog\ConsoleCommandContextProvider;
use SanderMuller\Stopwatch\RunLog\MarkdownRunRecorder;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\Tests\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ServiceProviderRunLogTest extends TestCase
{
    public function test_run_log_disabled_by_default(): void
    {
        $stopwatch = $this->app->make(Stopwatch::class);

        // Without enabling, recorders list stays empty — context resolution returns nothing.
        self::assertSame([], $stopwatch->resolveRunContext());
    }

    public function test_run_log_singletons_resolvable(): void
    {
        self::assertInstanceOf(RunLogStore::class, $this->app->make(RunLogStore::class));
        self::assertInstanceOf(MarkdownRunRecorder::class, $this->app->make(MarkdownRunRecorder::class));
        self::assertInstanceOf(ConsoleCommandContextProvider::class, $this->app->make(ConsoleCommandContextProvider::class));
    }

    public function test_run_log_path_defaults_to_storage_subdirectory(): void
    {
        $store = $this->app->make(RunLogStore::class);

        self::assertSame($this->app->storagePath('stopwatch/runs'), $store->path());
    }

    public function test_run_log_path_overridable_via_config(): void
    {
        $tempDir = sys_get_temp_dir() . '/stopwatch-config-' . bin2hex(random_bytes(4));

        // Force singleton rebuild by re-binding.
        $this->app->forgetInstance(RunLogStore::class);
        config()->set('stopwatch.run_log.path', $tempDir);

        $store = $this->app->make(RunLogStore::class);

        self::assertSame($tempDir, $store->path());
    }

    public function test_command_starting_provider_yields_command_context(): void
    {
        config()->set('stopwatch.run_log.enabled', true);
        config()->set('stopwatch.run_log.min_duration_ms', 0);
        $this->app->forgetInstance(Stopwatch::class);
        $this->app->forgetInstance(ConsoleCommandContextProvider::class);

        $stopwatch = $this->app->make(Stopwatch::class);

        // Simulate the artisan event the provider listens for.
        $this->app->make('events')->dispatch(new CommandStarting(
            'app:reindex',
            $this->createStub(InputInterface::class),
            $this->createStub(OutputInterface::class),
        ));

        // Even after start() → reset() the command context survives because the provider
        // is evaluated lazily at finish() time.
        $stopwatch->start();
        $stopwatch->checkpoint('Step');

        self::assertSame(['command' => 'app:reindex'], $stopwatch->resolveRunContext());
    }
}
