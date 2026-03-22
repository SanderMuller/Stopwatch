<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Carbon\CarbonImmutable;

final class SystemClock implements Clock
{
    public function hrtime(): int
    {
        return hrtime(true);
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
