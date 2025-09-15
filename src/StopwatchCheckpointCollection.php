<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
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
    public function addCheckpoint(string $label, ?array $metadata, CarbonImmutable $stopwatchStartTime, CarbonInterval $timeSinceLastCheckpoint): self
    {
        return $this->add(
            new StopwatchCheckpoint(
                label: $label,
                metadata: $metadata,
                timeSinceLastCheckpoint: $timeSinceLastCheckpoint,
                stopwatchStartTime: $stopwatchStartTime,
            ),
        );
    }

    public function render(Stopwatch $stopWatch, int $slowThreshold): string
    {
        return $this->implode(
            static fn (StopwatchCheckpoint $stopwatchCheckpoint): string => $stopwatchCheckpoint->render($stopWatch, $slowThreshold),
        );
    }

    public function lastCheckpoint(): ?StopwatchCheckpoint
    {
        return $this->last();
    }
}
