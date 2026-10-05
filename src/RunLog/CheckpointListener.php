<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchCheckpoint;

/**
 * Receives each checkpoint while the run is active, and the end of the run.
 * Unlike {@see RunRecorder}, which only sees finished runs, a listener also
 * hears about runs that restart or are still active at process shutdown.
 *
 * @internal
 */
interface CheckpointListener
{
    public function onCheckpoint(Stopwatch $stopwatch, StopwatchCheckpoint $checkpoint): void;

    public function onRunEnd(Stopwatch $stopwatch, RunEndReason $reason): void;
}
