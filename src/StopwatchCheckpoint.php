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
     * @param list<array{sql: string, bindings: array<array-key, mixed>, durationMs: float}>|null $queryCalls
     * @param list<array{method: string, url: string, status: int, durationMs: float}>|null $httpCalls
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
        public ?int            $httpCount = null,
        public ?float          $httpTimeMs = null,
        public ?array          $httpCalls = null,
        public ?array          $queryCalls = null,
        public bool            $probe = false,
        public ?string         $location = null,
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

        if ($this->httpCount !== null) {
            $parts[] = "{$this->httpCount}h / " . round($this->httpTimeMs ?? 0, 1) . 'ms';
        }

        if ($this->memoryDelta !== null) {
            $parts[] = self::formatMemoryDelta($this->memoryDelta);
        }

        $suffix = $parts !== [] ? ' (' . implode(', ', $parts) . ')' : '';

        return "[{$deltaMs}ms / {$totalMs}ms] {$this->label}{$suffix}";
    }

    /**
     * Flags for every JSON encoding of user-supplied values. Invalid UTF-8 is
     * substituted and unsupported parts (resources, recursion) become partial
     * output, so one bad value never empties the whole encoding.
     */
    public const int SAFE_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     * Cap for non-scalar values in plain text, HTML and Debugbar output.
     */
    public const int METADATA_DISPLAY_MAX_CHARS = 200;

    /**
     * Scalars and Stringables render as-is. Other values render as compact JSON,
     * cut to `$maxChars` characters when a cap is given.
     */
    public static function formatMetadataValue(mixed $value, ?int $maxChars = null): string
    {
        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        $json = json_encode($value, self::SAFE_JSON_FLAGS);

        if ($json === false) {
            return 'unencodable value (' . gettype($value) . ')';
        }

        if ($maxChars !== null && mb_strlen($json) > $maxChars) {
            return mb_substr($json, 0, $maxChars) . '…';
        }

        return $json;
    }

    private function formatMetadataAsString(): string
    {
        return collect($this->metadata)
            ->map(static fn (mixed $value, string|int $key): string => "{$key}=" . self::formatMetadataValue($value, self::METADATA_DISPLAY_MAX_CHARS))
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
     *     httpCount: int|null,
     *     httpTimeMs: float|null,
     *     httpCalls: list<array{method: string, url: string, status: int, durationMs: float}>|null,
     *     queryCalls: list<array{sql: string, bindings: array<array-key, mixed>, durationMs: float}>|null,
     *     probe: bool,
     *     location: string|null,
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
            'httpCount' => $this->httpCount,
            'httpTimeMs' => $this->httpTimeMs,
            'httpCalls' => $this->httpCalls,
            'queryCalls' => $this->queryCalls,
            'probe' => $this->probe,
            'location' => $this->location,
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
