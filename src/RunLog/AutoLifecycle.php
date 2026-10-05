<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use SanderMuller\Stopwatch\Stopwatch;
use Throwable;

/**
 * Starts and finishes runs at the outermost artisan command and at queued jobs,
 * so commands and jobs reach the run log without manual start()/finish().
 *
 * - Commands: only the outermost command owns a run; a nested `Artisan::call()` joins it.
 *   `stopwatch:*` commands never create runs.
 * - Jobs: a job owns a run unless it is a {@see SyncJob} inside an active run.
 *   A sync job is detected by class, not by connection name (which is
 *   user-configured). A `DeferredQueue` job runs after the response, when the
 *   request run has ended, so it owns a new run.
 * - Failures: `JobExceptionOccurred` finishes the run, also without checkpoints.
 *   `JobFailed` records the exception: it comes first on the worker path, after
 *   on the sync queue, and alone when a job calls `$this->fail()`.
 * - An owned run without checkpoints that ends normally is dropped.
 *
 * Registered at boot so the first command's `CommandStarting` is seen; the
 * stopwatch is resolved only when an event fires.
 *
 * @internal
 */
final class AutoLifecycle
{
    private int $commandDepth = 0;

    private bool $commandOwnsRun = false;

    /** @var array<int, true> jobs (by object id) that own the active run */
    private array $jobOwners = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, $this->commandStarting(...));
        $events->listen(CommandFinished::class, $this->commandFinished(...));
        $events->listen(JobProcessing::class, $this->jobProcessing(...));
        $events->listen(JobProcessed::class, $this->jobProcessed(...));
        $events->listen(JobFailed::class, $this->jobFailed(...));
        $events->listen(JobExceptionOccurred::class, $this->jobExceptionOccurred(...));
    }

    public function commandStarting(CommandStarting $event): void
    {
        $this->commandDepth++;

        if ($this->commandDepth !== 1 || $event->command === '' || str_starts_with($event->command, 'stopwatch:') || ! self::enabled()) {
            return;
        }

        // Set the command here: the context provider may be created during this very
        // dispatch (when it resolves the stopwatch) and then misses the event.
        $this->stopwatch()->start()->withRunContext(['command' => $event->command]);
        $this->commandOwnsRun = true;
    }

    public function commandFinished(): void
    {
        if ($this->commandDepth === 1 && $this->commandOwnsRun) {
            $this->commandOwnsRun = false;
            $this->finishUnlessEmpty();
        }

        $this->commandDepth = max(0, $this->commandDepth - 1);
    }

    public function jobProcessing(JobProcessing $event): void
    {
        if (! self::enabled()) {
            return;
        }

        $stopwatch = $this->stopwatch();
        $runActive = $stopwatch->started() && ! $stopwatch->ended();

        if ($event->job instanceof SyncJob && $runActive) {
            return;
        }

        $stopwatch->start();
        $stopwatch->withRunContext(['job' => $this->jobName($event->job)]);
        $this->jobOwners[spl_object_id($event->job)] = true;
    }

    public function jobProcessed(JobProcessed $event): void
    {
        if ($this->releaseOwnership($event->job)) {
            $this->finishUnlessEmpty();
        }
    }

    public function jobFailed(JobFailed $event): void
    {
        if (isset($this->jobOwners[spl_object_id($event->job)])) {
            $this->recordException($event->exception);
        }
    }

    public function jobExceptionOccurred(JobExceptionOccurred $event): void
    {
        if (! $this->releaseOwnership($event->job)) {
            return;
        }

        $this->recordException($event->exception);
        $this->stopwatch()->finish();
    }

    /**
     * An owned run that reached no checkpoint is dropped, not recorded: `php artisan
     * test` runs its probes in a child process, and the parent's empty run would
     * otherwise become the newest run that `stopwatch:runs:show latest` picks.
     */
    private function finishUnlessEmpty(): void
    {
        $stopwatch = $this->stopwatch();

        if ($stopwatch->checkpoints() === []) {
            $stopwatch->reset();

            return;
        }

        $stopwatch->finish();
    }

    private function recordException(Throwable $exception): void
    {
        $this->stopwatch()
            ->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, $exception)
            ->withRunContext(['threw' => true]);
    }

    private function releaseOwnership(Job $job): bool
    {
        $key = spl_object_id($job);

        if (! isset($this->jobOwners[$key])) {
            return false;
        }

        unset($this->jobOwners[$key]);

        return true;
    }

    private function jobName(Job $job): string
    {
        try {
            return $job->resolveName();
        } catch (Throwable) {
            return $job->getName();
        }
    }

    /**
     * Checked per event as well as at registration, so a runtime config change
     * (or a test that changes config after boot) is respected.
     */
    /**
     * @internal Also the registration check in {@see RunLogServiceRegistrar}.
     */
    public static function enabled(): bool
    {
        return config('stopwatch.enabled', true) !== false
            && (config('stopwatch.run_log.auto_lifecycle') === true || DebugMode::active());
    }

    private function stopwatch(): Stopwatch
    {
        return $this->container->make(Stopwatch::class);
    }
}
