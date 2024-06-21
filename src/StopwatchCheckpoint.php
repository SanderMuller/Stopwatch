<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Support\Arrayable;
use Override;
use Stringable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class StopwatchCheckpoint implements Arrayable
{
    public CarbonImmutable $time;

    public CarbonInterval $timeSinceStopwatchStart;

    public string $totalTimeElapsedFormatted;

    public CarbonInterval $timeSinceLastCheckpoint;

    public string $timeSinceLastCheckpointFormatted;

    /**
     * @param array{int|string, mixed}|null $metadata
     */
    public function __construct(
        public string   $label,
        public ?array   $metadata,
        ?self           $previousCheckpoint,
        CarbonImmutable $stopwatchStartTime,
    ) {
        $this->time = CarbonImmutable::now();

        $this->timeSinceStopwatchStart = $this->time->diffAsCarbonInterval($stopwatchStartTime)->cascade();

        $this->timeSinceLastCheckpoint = $previousCheckpoint !== null
            ? $this->time->diffAsCarbonInterval($previousCheckpoint->time)->cascade()
            : $this->timeSinceStopwatchStart;

        $this->timeSinceLastCheckpointFormatted = round($this->timeSinceLastCheckpoint->totalMilliseconds, 1) . 'ms';

        $this->totalTimeElapsedFormatted = round($this->timeSinceStopwatchStart->totalMilliseconds, 1) . 'ms';
    }

    public function render(Stopwatch $stopWatch): string
    {
        $factorRunDurationForThisCheckpoint = $this->timeSinceLastCheckpoint->totalMilliseconds / $stopWatch->totalRunDuration()->totalMilliseconds;
        $percentageRunDurationForThisCheckpoint = round($factorRunDurationForThisCheckpoint * 100);

        $bgColor = match(true) {
            $factorRunDurationForThisCheckpoint > 0.45 || $this->timeSinceLastCheckpoint->totalMilliseconds >= 400 => 'rgba(255, 25, 25, 0.7)',
            $factorRunDurationForThisCheckpoint > 0.4 || $this->timeSinceLastCheckpoint->totalMilliseconds >= 300 => 'rgba(255, 25, 25, 0.6)',
            $factorRunDurationForThisCheckpoint > 0.3 || $this->timeSinceLastCheckpoint->totalMilliseconds >= 200 => 'rgba(255, 25, 25, 0.5)',
            $factorRunDurationForThisCheckpoint > 0.2 || $this->timeSinceLastCheckpoint->totalMilliseconds >= 150 => 'rgba(255, 25, 25, 0.4)',
            $factorRunDurationForThisCheckpoint > 0.1 || $this->timeSinceLastCheckpoint->totalMilliseconds >= 100 => 'rgba(255, 25, 25, 0.15)',
            default => 'transparent',
        };

        return <<<HTML
            <div style="display: flex; justify-content: space-between; border-top: 1px solid rgb(243 244 246); padding: 12px 15px;">
                <div style="display: flex; flex-direction: column; line-height: 0.9;">
                    <label>{$this->label}</label>

                    <span style="font-size: 80%; color: #aaa;">{$percentageRunDurationForThisCheckpoint}%</span>

                    {$this->renderMetadata()}
                </div>

                <div style="display: flex; align-items: flex-end; flex-direction: column; line-height: 1.05; cursor: default; padding-left: 12px;">
                    <span style="font-weight: bold; padding: 2px 3px; background-color: {$bgColor};"
                          title="Execution time for '{$this->label}' (since previous checkpoint)">
                        {$this->timeSinceLastCheckpointFormatted}
                    </span>

                    <span style="font-size: 90%; padding: 2px 3px; color: #6a6a6a;"
                          title="Cumulative time at this point">
                        {$this->totalTimeElapsedFormatted}
                    </span>
                </div>
            </div>
        HTML;
    }

    private function renderMetadata(): string
    {
        if ($this->metadata === null) {
            return '';
        }

        $contents = collect($this->metadata)
            ->implode(static function (mixed $value, string|int $key): string {
                if (! is_scalar($value) && ! $value instanceof Stringable) {
                    $value = 'non-scalar value (' . gettype($value) . ')';
                }

                return "<strong>{$key}:</strong> {$value}<br/>";
            });

        return <<<HTML
            <div style="padding: 5px 10px; margin: 5px 0 0; background-color: #fcfcfc; border: 1px solid rgb(243 244 246); border-radius: 5px; line-height: 1.2;">
                {$contents}
            </div>
        HTML;
    }

    /**
     * @return array{
     *     label: string,
     *     time: string,
     *     metadata: array{int|string, mixed}|null,
     *     totalTimeElapsedMs: int,
     *     totalTimeElapsedFormatted: string,
     *     timeSinceLastCheckpointMs: int,
     *     timeSinceLastCheckpointFormatted: string,
     * }
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'time' => $this->time->format('H:i:s.u'),
            'metadata' => $this->metadata,
            'totalTimeElapsedMs' => (int) $this->timeSinceStopwatchStart->totalMilliseconds,
            'totalTimeElapsedFormatted' => $this->totalTimeElapsedFormatted,
            'timeSinceLastCheckpointMs' => (int) $this->timeSinceLastCheckpoint->totalMilliseconds,
            'timeSinceLastCheckpointFormatted' => $this->timeSinceLastCheckpointFormatted,
        ];
    }
}
