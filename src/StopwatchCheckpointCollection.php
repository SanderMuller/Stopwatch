<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * @extends Collection<array-key, StopwatchCheckpoint>
 * @property StopwatchCheckpoint[] $items
 * @method self add(StopwatchCheckpoint $item)
 */
final class StopwatchCheckpointCollection extends Collection
{
    /**
     * @param array<array-key, mixed>|null $metadata
     */
    public function addCheckpoint(
        string          $label,
        ?array          $metadata,
        float           $timeSinceLastCheckpointMs,
        float           $timeSinceStopwatchStartMs,
        CarbonImmutable $time,
        ?int            $queryCount = null,
        ?float          $queryTimeMs = null,
        ?int            $memoryUsage = null,
        ?int            $memoryDelta = null,
        ?int            $memoryPeak = null,
    ): self {
        return $this->add(
            new StopwatchCheckpoint(
                label: $label,
                metadata: $metadata,
                timeSinceLastCheckpointMs: $timeSinceLastCheckpointMs,
                timeSinceStopwatchStartMs: $timeSinceStopwatchStartMs,
                time: $time,
                queryCount: $queryCount,
                queryTimeMs: $queryTimeMs,
                memoryUsage: $memoryUsage,
                memoryDelta: $memoryDelta,
                memoryPeak: $memoryPeak,
            ),
        );
    }

    public function render(float $totalMs, int $slowThreshold): string
    {
        return $this->implode(
            static fn (StopwatchCheckpoint $stopwatchCheckpoint): string => $stopwatchCheckpoint->render($totalMs, $slowThreshold),
        );
    }

    public function lastCheckpoint(): ?StopwatchCheckpoint
    {
        return $this->last();
    }
}
