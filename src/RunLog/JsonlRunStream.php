<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use Closure;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchCheckpoint;

/**
 * Debug stream: appends one JSON record per checkpoint to `<runs dir>/<runId>.jsonl`
 * while the run is active, so probes survive a crash and need no `finish()`.
 *
 * Records: `start` (on the first checkpoint), `checkpoint`, `truncated` (once, at
 * the first checkpoint over `maxRecords`) and `end` (reason `finished`,
 * `restarted` or `shutdown`).
 * A run without checkpoints leaves no file.
 *
 * Only the process that opened a run appends to it: a `fork` child writes nothing
 * to a run it inherited.
 *
 * @internal
 */
final class JsonlRunStream implements CheckpointListener
{
    private ?string $runId = null;

    private ?int $ownerPid = null;

    private int $written = 0;

    private int $dropped = 0;

    private bool $failureLogged = false;

    /** @var Closure(): int */
    private readonly Closure $pid;

    /**
     * @param (Closure(): int)|null $pid test seam for the current process id
     */
    public function __construct(
        private readonly RunLogStore $store,
        private readonly int $maxRecords = 1000,
        private readonly int $valueMaxBytes = 4096,
        private readonly ?int $maxRuns = null,
        ?Closure $pid = null,
    ) {
        $this->pid = $pid ?? static fn (): int => (int) getmypid();
    }

    public function onCheckpoint(Stopwatch $stopwatch, StopwatchCheckpoint $checkpoint): void
    {
        $runId = $stopwatch->runId();

        if ($runId === null) {
            return;
        }

        if ($runId !== $this->runId) {
            $this->openRun($stopwatch, $runId);
        }

        if (! $this->ownsRun()) {
            return;
        }

        if ($this->written >= $this->maxRecords) {
            if ($this->dropped === 0) {
                $this->append(['type' => 'truncated', 'after' => $this->maxRecords]);
            }

            $this->dropped++;

            return;
        }

        $this->written++;
        $this->append(JsonlRecord::checkpoint($checkpoint, $this->written, $this->valueMaxBytes));
    }

    public function onRunEnd(Stopwatch $stopwatch, RunEndReason $reason): void
    {
        if ($this->runId === null || $stopwatch->runId() !== $this->runId || ! $this->ownsRun()) {
            return;
        }

        $this->append([
            'type' => 'end',
            'reason' => $reason->value,
            'duration_ms' => round($stopwatch->totalRunDuration()->totalMilliseconds, 3),
            'checkpoints' => $this->written + $this->dropped,
            'dropped' => $this->dropped,
        ]);

        $this->runId = null;
        $this->ownerPid = null;
    }

    private function openRun(Stopwatch $stopwatch, string $runId): void
    {
        $this->runId = $runId;
        $this->ownerPid = ($this->pid)();
        $this->written = 0;
        $this->dropped = 0;

        $context = $stopwatch->resolveRunContext();

        $this->append([
            'type' => 'start',
            'run' => $runId,
            'at' => $stopwatch->startTime()?->format('Y-m-d\TH:i:s.vP'),
            'url' => $context['url'] ?? null,
            'method' => $context['method'] ?? null,
            'command' => $context['command'] ?? null,
            'job' => $context['job'] ?? null,
        ]);

        // JSONL-only runs (tests, crashes) never reach the recorder's prune.
        if ($this->maxRuns !== null && random_int(1, 100) <= 5) {
            $this->store->pruneByCount($this->maxRuns);
        }
    }

    private function ownsRun(): bool
    {
        return $this->ownerPid === ($this->pid)();
    }

    /**
     * @param array<string, mixed> $record
     */
    private function append(array $record): void
    {
        if ($this->runId === null) {
            return;
        }

        $line = json_encode($record, StopwatchCheckpoint::SAFE_JSON_FLAGS);

        if ($line !== false && $this->store->appendLine($this->runId, $line)) {
            return;
        }

        if ($this->failureLogged) {
            return;
        }

        $this->failureLogged = true;

        try {
            logger()->warning('Stopwatch debug stream could not write to ' . $this->store->path());
        } catch (\Throwable) {
            // No logger: the request must still complete.
        }
    }
}
