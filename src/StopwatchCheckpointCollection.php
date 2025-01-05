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
    public function addCheckpoint(string $label, ?array $metadata, CarbonImmutable $stopwatchStartTime): self
    {
        return $this->add(
            new StopwatchCheckpoint(
                label: $label,
                metadata: $metadata,
                previousCheckpoint: $this->last(),
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
}
