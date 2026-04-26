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
