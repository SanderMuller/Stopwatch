<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use SanderMuller\Stopwatch\Stopwatch;

/**
 * Persists a finished stopwatch run for later inspection.
 *
 * Implementations MUST NOT throw. Any I/O failure must be swallowed (and ideally
 * surfaced via the application logger) so a failed recorder never breaks the
 * request that produced the run.
 */
interface RunRecorder
{
    /**
     * @param array<string, scalar|null> $context request/command context (url, method, status, command, threw, ...)
     */
    public function record(Stopwatch $stopwatch, array $context): void;
}
