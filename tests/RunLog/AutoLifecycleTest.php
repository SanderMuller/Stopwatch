<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use Illuminate\Bus\Queueable;
use Illuminate\Console\Command;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchMiddleware;
use SanderMuller\Stopwatch\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class AutoLifecycleTest extends TestCase
{
    private static string $dir = '';

    protected function defineEnvironment($app): void
    {
        self::$dir = sys_get_temp_dir() . '/stopwatch-auto-' . uniqid();
        $app['config']->set('stopwatch.run_log.path', self::$dir);
        $app['config']->set('stopwatch.run_log.enabled', true);
        $app['config']->set('stopwatch.run_log.min_duration_ms', 0);
        $app['config']->set('stopwatch.run_log.auto_lifecycle', true);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.connections.local', ['driver' => 'sync']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel only bridges Symfony console events to CommandStarting/CommandFinished
        // outside unit tests (Foundation\Console\Kernel::__construct). Turn the bridge on
        // and rebuild artisan so it picks up the dispatcher, as in a real `php artisan` run.
        $kernel = $this->app->make(Kernel::class);
        self::assertInstanceOf(ConsoleKernel::class, $kernel);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);

        foreach ([new ProbeEmptyCommand(), new ProbeOneCommand(), new ProbeInnerCommand(), new ProbeOuterCommand(), new ProbeDispatchCommand(), new ProbeRestartCommand()] as $command) {
            $kernel->registerCommand($command);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(self::$dir);

        parent::tearDown();
    }

    public function test_an_artisan_command_becomes_one_run(): void
    {

        $this->artisan('probe:one')->assertSuccessful();

        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertSame('probe:one', $runs[0]['frontmatter']['command']);
        self::assertStringContainsString('inside command', $this->body($runs[0]['id']));
    }

    public function test_without_auto_lifecycle_a_command_records_nothing(): void
    {
        config()->set('stopwatch.run_log.auto_lifecycle', false);

        $this->artisan('probe:one')->assertSuccessful();

        self::assertSame([], $this->runs());
    }

    public function test_a_nested_artisan_call_joins_the_outer_run(): void
    {

        $this->artisan('probe:outer')->assertSuccessful();

        $runs = $this->runs();
        self::assertCount(1, $runs);
        $body = $this->body($runs[0]['id']);
        self::assertStringContainsString('outer before', $body);
        self::assertStringContainsString('inner', $body);
        self::assertStringContainsString('outer after', $body);
    }

    public function test_stopwatch_commands_never_create_runs(): void
    {
        $this->artisan('stopwatch:runs:list')->assertSuccessful();

        self::assertFalse($this->app->make(Stopwatch::class)->started());
    }

    public function test_a_sync_job_inside_a_command_joins_the_command_run(): void
    {

        $this->artisan('probe:dispatch')->assertSuccessful();

        $runs = $this->runs();
        self::assertCount(1, $runs);
        $body = $this->body($runs[0]['id']);
        self::assertSame(2, substr_count($body, 'inside job'));
        self::assertStringContainsString('after dispatch', $body);
    }

    public function test_a_sync_job_inside_a_request_joins_the_request_run(): void
    {
        Route::get('/dispatch-in-request', static function (): string {
            stopwatch()->checkpoint('request before');
            AutoLifecycleProbeJob::dispatch();

            return 'ok';
        })->middleware(StopwatchMiddleware::autoStart());

        $this->get('/dispatch-in-request')->assertOk();

        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertNull($runs[0]['frontmatter']['job']);
        self::assertStringContainsString('inside job', $this->body($runs[0]['id']));
    }

    public function test_a_deferred_sync_job_after_the_run_ended_owns_a_new_run(): void
    {
        $stopwatch = $this->app->make(Stopwatch::class);
        $stopwatch->checkpoint('request');
        $stopwatch->finish();

        $job = new SyncJob($this->app, (string) json_encode(['displayName' => 'App\\Jobs\\Deferred', 'job' => 'x', 'data' => []]), 'deferred', 'default');
        event(new JobProcessing('deferred', $job));
        $stopwatch->checkpoint('inside deferred job');
        event(new JobProcessed('deferred', $job));

        $jobRuns = array_values(array_filter($this->runs(), static fn (array $run): bool => $run['frontmatter']['job'] === 'App\\Jobs\\Deferred'));
        self::assertCount(1, $jobRuns);
        self::assertStringContainsString('inside deferred job', $this->body($jobRuns[0]['id']));
    }

    public function test_a_queued_job_becomes_its_own_run(): void
    {
        $job = $this->queuedJob('App\\Jobs\\Remote');

        event(new JobProcessing('redis', $job));
        stopwatch()->checkpoint('inside remote job');
        event(new JobProcessed('redis', $job));

        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertSame('App\\Jobs\\Remote', $runs[0]['frontmatter']['job']);
    }

    public function test_a_retried_job_gives_one_run_per_attempt(): void
    {
        $first = $this->queuedJob('App\\Jobs\\Flaky');
        event(new JobProcessing('redis', $first));
        stopwatch()->checkpoint('attempt one');
        event(new JobExceptionOccurred('redis', $first, new RuntimeException('boom')));

        $second = $this->queuedJob('App\\Jobs\\Flaky');
        event(new JobProcessing('redis', $second));
        stopwatch()->checkpoint('attempt two');
        event(new JobProcessed('redis', $second));

        $runs = $this->runs();
        self::assertCount(2, $runs);
        $threw = array_filter($runs, static fn (array $run): bool => ($run['frontmatter']['threw'] ?? false) === true);
        self::assertCount(1, $threw);
    }

    public function test_a_permanently_failed_job_is_one_run_with_the_exception(): void
    {
        $job = $this->queuedJob('App\\Jobs\\Broken');
        $exception = new RuntimeException('gave up');

        event(new JobProcessing('redis', $job));
        stopwatch()->checkpoint('before failure');
        event(new JobFailed('redis', $job, $exception));
        event(new JobExceptionOccurred('redis', $job, $exception));

        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertTrue($runs[0]['frontmatter']['threw']);
        self::assertSame(RuntimeException::class, $runs[0]['frontmatter']['exception_class']);
    }

    public function test_a_job_that_fails_itself_records_the_exception_from_job_failed(): void
    {
        // `$this->fail($e)` inside a job fires JobFailed, then JobProcessed, and no JobExceptionOccurred.
        $job = $this->queuedJob('App\\Jobs\\FailsItself');

        event(new JobProcessing('redis', $job));
        stopwatch()->checkpoint('before fail()');
        event(new JobFailed('redis', $job, new RuntimeException('failed on purpose')));
        event(new JobProcessed('redis', $job));

        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertSame(RuntimeException::class, $runs[0]['frontmatter']['exception_class']);
    }

    public function test_queue_work_with_empty_runs_kept_still_leaves_only_the_job_run(): void
    {
        config()->set('stopwatch.run_log.skip_empty', false);
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $job = $this->queuedJob('App\\Jobs\\Worked');

        event(new CommandStarting('queue:work', $input, $output));
        event(new JobProcessing('redis', $job));
        stopwatch()->checkpoint('inside worked job');
        event(new JobProcessed('redis', $job));
        event(new CommandFinished('queue:work', $input, $output, 0));

        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertSame('App\\Jobs\\Worked', $runs[0]['frontmatter']['job']);
    }

    public function test_queue_work_leaves_only_the_job_runs(): void
    {
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $job = $this->queuedJob('App\\Jobs\\Worked');

        event(new CommandStarting('queue:work', $input, $output));
        event(new JobProcessing('redis', $job));
        stopwatch()->checkpoint('inside worked job');
        event(new JobProcessed('redis', $job));
        event(new CommandFinished('queue:work', $input, $output, 0));

        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertSame('App\\Jobs\\Worked', $runs[0]['frontmatter']['job']);
    }

    public function test_a_command_without_checkpoints_leaves_no_run(): void
    {
        // `php artisan test` is a command whose probes run in a child process: its own
        // empty run must not become `latest`.
        config()->set('stopwatch.run_log.skip_empty', false);

        $this->artisan('stopwatch-probe:empty')->assertSuccessful();

        self::assertSame([], $this->runs());
    }

    public function test_a_failed_job_without_checkpoints_still_records_the_exception(): void
    {
        config()->set('stopwatch.run_log.skip_empty', false);
        $job = $this->queuedJob('App\\Jobs\\FailsAtOnce');

        event(new JobProcessing('redis', $job));
        event(new JobExceptionOccurred('redis', $job, new RuntimeException('no checkpoint reached')));

        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertTrue($runs[0]['frontmatter']['threw']);
    }

    public function test_start_inside_a_command_restarts_the_stream(): void
    {
        config()->set('stopwatch.debug.enabled', true);
        config()->set('app.debug', true);
        $this->app->forgetInstance(Stopwatch::class);

        $this->artisan('probe:restart')->assertSuccessful();

        $ids = ProbeRestartCommand::$ids;
        $lines = file(self::$dir . '/' . $ids[0] . '.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
        $last = json_decode((string) end($lines), true);
        self::assertIsArray($last);
        self::assertSame('restarted', $last['reason']);
        self::assertFileExists(self::$dir . '/' . $ids[1] . '.md');
    }

    private function queuedJob(string $name): Job
    {
        $job = Mockery::mock(Job::class);
        $job->allows('resolveName')->andReturn($name);
        $job->allows('getName')->andReturn($name);
        $job->allows('payload')->andReturn(['displayName' => $name]);

        return $job;
    }

    /**
     * @return list<array{id: string, frontmatter: array<string, scalar|null>, state: string}>
     */
    private function runs(): array
    {
        return $this->app->make(RunLogStore::class)->listRuns(100, 'recorded', false);
    }

    private function body(string $id): string
    {
        return (string) file_get_contents(self::$dir . '/' . $id . '.md');
    }
}

final class AutoLifecycleProbeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        stopwatch()->checkpoint('inside job');
    }
}

final class ProbeOneCommand extends Command
{
    protected $signature = 'probe:one';

    public function handle(): void
    {
        stopwatch()->checkpoint('inside command', ['user' => 42]);
    }
}

final class ProbeInnerCommand extends Command
{
    protected $signature = 'probe:inner';

    public function handle(): void
    {
        stopwatch()->checkpoint('inner');
    }
}

final class ProbeOuterCommand extends Command
{
    protected $signature = 'probe:outer';

    public function handle(): void
    {
        stopwatch()->checkpoint('outer before');
        Artisan::call('probe:inner');
        stopwatch()->checkpoint('outer after');
    }
}

final class ProbeDispatchCommand extends Command
{
    protected $signature = 'probe:dispatch';

    public function handle(): void
    {
        stopwatch()->checkpoint('before dispatch');
        AutoLifecycleProbeJob::dispatch();
        AutoLifecycleProbeJob::dispatch()->onConnection('local');
        stopwatch()->checkpoint('after dispatch');
    }
}

final class ProbeRestartCommand extends Command
{
    /** @var list<string|null> */
    public static array $ids = [];

    protected $signature = 'probe:restart';

    public function handle(): void
    {
        self::$ids = [];
        stopwatch()->checkpoint('before own start');
        self::$ids[] = stopwatch()->runId();
        stopwatch()->start();
        stopwatch()->checkpoint('after own start');
        self::$ids[] = stopwatch()->runId();
    }
}

final class ProbeEmptyCommand extends Command
{
    protected $signature = 'stopwatch-probe:empty';

    public function handle(): void {}
}
