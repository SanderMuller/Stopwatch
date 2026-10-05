<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\File;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\Tests\TestCase;

/**
 * STOPWATCH_DEBUG alone must give commands their own run: `run_log.auto_lifecycle`
 * stays false at boot, so only the debug-mode branch of the boot gate registers
 * the listeners.
 */
final class DebugModeAutoLifecycleTest extends TestCase
{
    private static string $dir = '';

    protected function defineEnvironment($app): void
    {
        self::$dir = sys_get_temp_dir() . '/stopwatch-debug-auto-' . uniqid();
        $app['config']->set('stopwatch.run_log.path', self::$dir);
        $app['config']->set('stopwatch.run_log.auto_lifecycle', false);
        $app['config']->set('stopwatch.debug.enabled', true);
        $app['config']->set('app.debug', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = $this->app->make(Kernel::class);
        self::assertInstanceOf(ConsoleKernel::class, $kernel);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);
        $kernel->registerCommand(new DebugModeProbeCommand());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(self::$dir);

        parent::tearDown();
    }

    public function test_debug_mode_alone_turns_commands_into_runs(): void
    {
        $this->artisan('debug-probe:run')->assertSuccessful();

        $runs = $this->app->make(RunLogStore::class)->listRuns(10, 'recorded');

        self::assertCount(1, $runs);
        self::assertSame('debug-probe:run', $runs[0]['frontmatter']['command']);
        self::assertFileExists(self::$dir . '/' . $runs[0]['id'] . '.jsonl');
    }
}

final class DebugModeProbeCommand extends Command
{
    protected $signature = 'debug-probe:run';

    public function handle(): void
    {
        stopwatch()->probe('inside debug command');
    }
}
