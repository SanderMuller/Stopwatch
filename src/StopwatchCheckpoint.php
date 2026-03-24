<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Support\Arrayable;
use Stringable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class StopwatchCheckpoint implements Arrayable
{
    public CarbonInterval $timeSinceLastCheckpoint;

    public CarbonInterval $timeSinceStopwatchStart;

    public string $totalTimeElapsedFormatted;

    public string $timeSinceLastCheckpointFormatted;

    /**
     * @param array<array-key, mixed>|null $metadata
     */
    public function __construct(
        public string          $label,
        public ?array          $metadata,
        float                  $timeSinceLastCheckpointMs,
        float                  $timeSinceStopwatchStartMs,
        public CarbonImmutable $time,
        public ?int            $queryCount = null,
        public ?float          $queryTimeMs = null,
        public ?int            $memoryUsage = null,
        public ?int            $memoryDelta = null,
        public ?int            $memoryPeak = null,
    ) {
        $this->timeSinceLastCheckpoint = CarbonInterval::milliseconds($timeSinceLastCheckpointMs)->cascade();
        $this->timeSinceStopwatchStart = CarbonInterval::milliseconds($timeSinceStopwatchStartMs)->cascade();

        $this->timeSinceLastCheckpointFormatted = round($timeSinceLastCheckpointMs, 1) . 'ms';

        $this->totalTimeElapsedFormatted = round($timeSinceStopwatchStartMs, 1) . 'ms';
    }

    public function render(float $totalMs, int $slowThreshold): string
    {
        $runDurationMs = $this->timeSinceLastCheckpoint->totalMilliseconds;

        $factorRunDurationForThisCheckpoint = $totalMs > 0 ? $runDurationMs / $totalMs : 0;
        $percentageRunDurationForThisCheckpoint = round($factorRunDurationForThisCheckpoint * 100);

        if ($runDurationMs < $slowThreshold) {
            $bgColor = 'transparent';
        } else {
            $bgColor = match (true) {
                $factorRunDurationForThisCheckpoint > 0.45 || $runDurationMs >= 400 => 'rgba(255, 25, 25, 0.7)',
                $factorRunDurationForThisCheckpoint > 0.4 || $runDurationMs >= 300 => 'rgba(255, 25, 25, 0.6)',
                $factorRunDurationForThisCheckpoint > 0.3 || $runDurationMs >= 200 => 'rgba(255, 25, 25, 0.5)',
                $factorRunDurationForThisCheckpoint > 0.2 || $runDurationMs >= 150 => 'rgba(255, 25, 25, 0.4)',
                $factorRunDurationForThisCheckpoint > 0.1 || $runDurationMs >= 100 => 'rgba(255, 25, 25, 0.15)',
                default => 'transparent',
            };
        }

        $escapedLabel = htmlspecialchars($this->label, ENT_QUOTES | ENT_SUBSTITUTE);

        return <<<HTML
            <div style="display: flex; justify-content: space-between; border-top: 1px solid rgb(243 244 246); padding: 12px 15px;">
                <div style="display: flex; flex-direction: column; line-height: 1.2;">
                    <label>{$escapedLabel}</label>

                    <span style="font-size: 80%; color: #aaa;">{$percentageRunDurationForThisCheckpoint}%</span>

                    {$this->renderMetadata()}
                </div>

                <div style="display: flex; align-items: flex-end; flex-direction: column; line-height: 1.05; cursor: default; padding-left: 12px;">
                    <span style="font-weight: bold; padding: 2px 3px; background-color: {$bgColor};"
                          title="Execution time for '{$escapedLabel}' (since previous checkpoint)">
                        {$this->timeSinceLastCheckpointFormatted}
                    </span>

                    <span style="font-size: 90%; padding: 2px 3px; color: #6a6a6a;"
                          title="Cumulative time at this point">
                        {$this->totalTimeElapsedFormatted}
                    </span>

                    {$this->renderQueryBadge()}
                    {$this->renderMemoryBadge()}
                </div>
            </div>
        HTML;
    }

    public function formattedPlainText(): string
    {
        $deltaMs = (int) round($this->timeSinceLastCheckpoint->totalMilliseconds);
        $totalMs = (int) round($this->timeSinceStopwatchStart->totalMilliseconds);

        $parts = [];

        if ($this->metadata !== null) {
            $parts[] = $this->formatMetadataAsString();
        }

        if ($this->queryCount !== null) {
            $parts[] = "{$this->queryCount}q / {$this->queryTimeMs}ms";
        }

        if ($this->memoryDelta !== null) {
            $parts[] = self::formatMemoryDelta($this->memoryDelta);
        }

        $suffix = $parts !== [] ? ' (' . implode(', ', $parts) . ')' : '';

        return "[{$deltaMs}ms / {$totalMs}ms] {$this->label}{$suffix}";
    }

    private function renderQueryBadge(): string
    {
        if ($this->queryCount === null) {
            return '';
        }

        $queryTimeFormatted = round($this->queryTimeMs ?? 0, 1) . 'ms';

        return <<<HTML
            <span style="font-size: 80%; padding: 2px 3px; color: #8b5cf6; cursor: default;"
                  title="{$this->queryCount} queries in {$queryTimeFormatted}">
                {$this->queryCount}q / {$queryTimeFormatted}
            </span>
        HTML;
    }

    private function renderMemoryBadge(): string
    {
        if ($this->memoryDelta === null) {
            return '';
        }

        $usage = self::formatBytes($this->memoryUsage ?? 0);
        $delta = self::formatMemoryDelta($this->memoryDelta);
        $peak = self::formatBytes($this->memoryPeak ?? 0);

        return <<<HTML
            <span style="font-size: 80%; padding: 2px 3px; color: #6b7280; cursor: default;"
                  title="Usage: {$usage} | Delta: {$delta} | Peak: {$peak}">
                {$delta}
            </span>
        HTML;
    }

    public static function formatMetadataValue(mixed $value): string
    {
        if (! is_scalar($value) && ! $value instanceof Stringable) {
            return 'non-scalar value (' . gettype($value) . ')';
        }

        return (string) $value;
    }

    private function formatMetadataAsString(): string
    {
        return collect($this->metadata)
            ->map(static fn (mixed $value, string|int $key): string => "{$key}=" . self::formatMetadataValue($value))
            ->implode(', ');
    }

    private function renderMetadata(): string
    {
        if ($this->metadata === null) {
            return '';
        }

        $contents = collect($this->metadata)
            ->implode(static fn (mixed $value, string|int $key): string => '<strong>' . htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE) . ':</strong> ' . htmlspecialchars(self::formatMetadataValue($value), ENT_QUOTES | ENT_SUBSTITUTE) . '<br/>');

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
     *     metadata: array<array-key, mixed>|null,
     *     totalTimeElapsedMs: int,
     *     totalTimeElapsedFormatted: string,
     *     timeSinceLastCheckpointMs: int,
     *     timeSinceLastCheckpointFormatted: string,
     *     queryCount: int|null,
     *     queryTimeMs: float|null,
     *     memoryUsage: int|null,
     *     memoryDelta: int|null,
     *     memoryPeak: int|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'time' => $this->time->format('H:i:s.u'),
            'metadata' => $this->metadata,
            'totalTimeElapsedMs' => (int) round($this->timeSinceStopwatchStart->totalMilliseconds),
            'totalTimeElapsedFormatted' => $this->totalTimeElapsedFormatted,
            'timeSinceLastCheckpointMs' => (int) round($this->timeSinceLastCheckpoint->totalMilliseconds),
            'timeSinceLastCheckpointFormatted' => $this->timeSinceLastCheckpointFormatted,
            'queryCount' => $this->queryCount,
            'queryTimeMs' => $this->queryTimeMs,
            'memoryUsage' => $this->memoryUsage,
            'memoryDelta' => $this->memoryDelta,
            'memoryPeak' => $this->memoryPeak,
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        $absBytes = abs($bytes);

        return match (true) {
            $absBytes >= 1048576 => round($bytes / 1048576, 1) . 'MB',
            $absBytes >= 1024 => round($bytes / 1024, 1) . 'KB',
            default => $bytes . 'B',
        };
    }

    public static function formatMemoryDelta(int $bytes): string
    {
        return ($bytes >= 0 ? '+' : '') . self::formatBytes($bytes);
    }
}
