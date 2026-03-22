<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Carbon\CarbonImmutable;

interface Clock
{
    /**
     * Returns the current monotonic time in nanoseconds.
     */
    public function hrtime(): int;

    /**
     * Returns the current wall-clock time for display purposes.
     */
    public function now(): CarbonImmutable;
}
