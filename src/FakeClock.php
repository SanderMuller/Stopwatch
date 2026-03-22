<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Carbon\CarbonImmutable;

final class FakeClock implements Clock
{
    private int $nanos = 0;

    private CarbonImmutable $time;

    public function __construct(?CarbonImmutable $startTime = null)
    {
        $this->time = $startTime ?? CarbonImmutable::parse('2026-01-15 10:30:00');
    }

    public function hrtime(): int
    {
        return $this->nanos;
    }

    public function now(): CarbonImmutable
    {
        return $this->time;
    }

    public function advance(int $milliseconds): self
    {
        $this->nanos += $milliseconds * 1_000_000;
        $this->time = $this->time->addMilliseconds($milliseconds);

        return $this;
    }
}
